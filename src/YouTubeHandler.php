<?php
namespace AnyDownloader\YouTubeDownloader;

use AnyDownloader\DownloadManager\Exception\BadResponseException;
use AnyDownloader\DownloadManager\Exception\NothingToExtractException;
use AnyDownloader\DownloadManager\Exception\NotValidUrlException;
use AnyDownloader\DownloadManager\Handler\BaseHandler;
use AnyDownloader\DownloadManager\Model\Attribute\AuthorAttribute;
use AnyDownloader\DownloadManager\Model\Attribute\Count\ViewsCountAttribute;
use AnyDownloader\DownloadManager\Model\Attribute\HashtagsAttribute;
use AnyDownloader\DownloadManager\Model\Attribute\TitleAttribute;
use AnyDownloader\DownloadManager\Model\Attribute\TextAttribute;
use AnyDownloader\DownloadManager\Model\FetchedResource;
use AnyDownloader\DownloadManager\Model\ResourceItem\Audio\AudioMP4ResourceItem;
use AnyDownloader\DownloadManager\Model\ResourceItem\ResourceItemFactory;
use AnyDownloader\DownloadManager\Model\ResourceItem\Text\XMLResourceItem;
use AnyDownloader\DownloadManager\Model\ResourceItem\Video\MP4ResourceItem;
use AnyDownloader\DownloadManager\Model\URL;
use AnyDownloader\YouTubeDownloader\Exception\NotValidYTItemException;
use AnyDownloader\YouTubeDownloader\Exception\TooManyRequestsException;
use AnyDownloader\YouTubeDownloader\Model\YouTubeFetchedResource;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class YouTubeHandler extends BaseHandler
{
    const SUCCESS_HTTP_CODE = 200;
    const TOO_MANY_REQUESTS_HTTP_CODE = 429;

    /**
     * @var string[]
     */
    protected $urlRegExPatterns = [
        'full' => '/[\/\/|www.|m.]youtube\.[a-z]+\/[watch|]{0,}[\?|]{0,1}[v|vi]{1,2}[\=|\/]{1}([a-zA-Z0-9-_]+)/',
        'short' => '/[\/\/|www.|m.]youtu\.be\/([a-zA-Z0-9-_]+)/',
        'embed' => '/[\/\/|www.|m.]youtube\.[a-z]+\/embed\/([a-zA-Z0-9-_]+)/',
    ];

    /**
     * @var HttpClientInterface
     */
    private $client;

    /**
     * YouTubeHandler constructor.
     * @param HttpClientInterface $client
     */
    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * @param URL $url
     * @return FetchedResource
     * @throws BadResponseException
     * @throws ClientExceptionInterface
     * @throws NotValidUrlException
     * @throws NothingToExtractException
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TooManyRequestsException
     * @throws TransportExceptionInterface
     */
    public function fetchResource(URL $url): FetchedResource
    {
        $vId = $this->extractVideoIdFromURL($url);
        if (empty($vId)) {
            throw new NotValidUrlException();
        }
        $data = $this->getVideoInfo($vId);
        $ytFetchedResource = new YouTubeFetchedResource($url);
        if (isset($data->videoDetails)) {
            if (isset($data->videoDetails->title)) {
                $ytFetchedResource->addAttribute(new TitleAttribute($data->videoDetails->title));
            }

            if (isset($data->videoDetails->text)) {
                $ytFetchedResource->addAttribute(new TextAttribute($data->videoDetails->text));
            }

            if (isset($data->videoDetails->keywords)) {
                $ytFetchedResource->addAttribute(HashtagsAttribute::fromStringArray($data->videoDetails->keywords));
            }

            if (isset($data->videoDetails->viewCount)) {
                $ytFetchedResource->addAttribute(new ViewsCountAttribute($data->videoDetails->viewCount));
            }

            if (isset($data->videoDetails->channelId)) {
                $ytFetchedResource->addAttribute(
                    new AuthorAttribute(
                        $data->videoDetails->channelId ?? null,
                        $data->videoDetails->author ?? null,
                        $data->videoDetails->author ?? null
                    )
                );
            }

            if (isset($data->videoDetails->thumbnail) && count($data->videoDetails->thumbnail->thumbnails)) {
                $thumbData = end($data->videoDetails->thumbnail->thumbnails);
                $thumbnail = ResourceItemFactory::fromURL(
                    URL::fromString($thumbData->url),
                    $thumbData->width . 'x' . $thumbData->height
                );
                $ytFetchedResource->setImagePreview($thumbnail);
                $ytFetchedResource->addItem($thumbnail);
            }
        }

        if (isset($data->captions)) {
            if(isset($data->captions->playerCaptionsTracklistRenderer->captionTracks)) {
                $captionTracks = $data->captions->playerCaptionsTracklistRenderer->captionTracks;
                foreach ($captionTracks as $captionTrack) {
                    $ytFetchedResource->addItem(
                        new XMLResourceItem(
                            URL::fromString($captionTrack->baseUrl),
                            $captionTrack->languageCode
                        )
                    );
                }
            }
        }

        $playerSource = $this->fetchPlayerSource($vId);

        if (isset($data->streamingData)) {
            foreach ($data->streamingData->formats as $item) {
                if (strpos($item->mimeType, MP4ResourceItem::MIMEType()) !== false) {
                    try {
                        $url = $this->getItemURL($item, $playerSource);
                    } catch (NotValidYTItemException $e) {
                        continue;
                    }
                    $title = $item->qualityLabel ?? $item->bitrate;
                    $resItem = new MP4ResourceItem($url, $title);
                    $ytFetchedResource->addItem($resItem);
                    $ytFetchedResource->setVideoPreview($resItem);
                }

            }

            foreach ($data->streamingData->adaptiveFormats as $item) {
                if (strpos($item->mimeType, AudioMP4ResourceItem::MIMEType()) !== false) {
                    try {
                        $url = $this->getItemURL($item, $playerSource);
                    } catch (NotValidYTItemException $e) {
                        continue;
                    }
                    $title = $item->qualityLabel ?? $item->bitrate;
                    $ytFetchedResource->addItem(new AudioMP4ResourceItem($url, $title));
                }
            }
        }

        return $ytFetchedResource;
    }

    /**
     * @param \stdClass $item
     * @param string $playerSource
     * @return URL|null
     * @throws NotValidUrlException
     * @throws NotValidYTItemException
     */
    private function getItemURL(\stdClass $item, string $playerSource): ?URL
    {
        if (isset($item->url)) {
            return URL::fromString($item->url);
        }
        if (isset($item->cipher) || isset($item->signatureCipher)) {
            return $this->getURLFromSignatureCipher(
                $item->cipher ?? $item->signatureCipher,
                $playerSource
            );
        }
        throw new NotValidYTItemException();
    }

    /**
     * @param string $cipher
     * @param string $playerSource
     * @return URL
     * @throws NotValidUrlException
     */
    private function getURLFromSignatureCipher(string $cipher, string $playerSource): URL
    {
        parse_str($cipher, $params);
        $decodedSign = (new SignatureDecoder())->decode($params['s'], $playerSource);
        return URL::fromString($params['url'] . '&' . $params['sp'] . '=' . $decodedSign);
    }

    /**
     * @param string $vId
     * @return \stdClass
     * @throws BadResponseException
     * @throws ClientExceptionInterface
     * @throws NotValidUrlException
     * @throws NothingToExtractException
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TooManyRequestsException
     * @throws TransportExceptionInterface
     */
    private function getVideoInfo(string $vId): \stdClass
    {
        $resp = $this->request(URL::fromString(
            'https://www.youtube.com/get_video_info?' . http_build_query([
                'video_id' => $vId,
                'eurl' => 'https://youtube.googleapis.com/v/' . $vId,
                'html5' => 1,
                'c' => 'TVHTML5',
                'cver' => '6.20180913'
            ])
        ));
        if (!preg_match('/(\{"responseContext(.*)\})&/', urldecode($resp->getContent()), $matches)) {
            throw new NothingToExtractException();
        }

        $content = json_decode($matches[1]);
        if (json_last_error()) {
            throw new NothingToExtractException();
        }
        return $content;
    }

    /**
     * @param string $vId
     * @return string
     * @throws BadResponseException
     * @throws ClientExceptionInterface
     * @throws NotValidUrlException
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TooManyRequestsException
     * @throws TransportExceptionInterface
     */
    private function fetchPlayerSource(string $vId): string
    {
        $url = "https://www.youtube.com/watch?" . http_build_query([
                'v' => $vId,
                'gl' => 'US',
                'hl' => 'en',
                'has_verified' => 1,
                'bpctr' => 9999999999
            ]);
        $content = $this->request(URL::fromString($url))->getContent();
        if (preg_match('/<script\s*src="([^"]+player[^"]+js)/', $content, $matches)) {
            $playerURL = 'https://www.youtube.com/' . $matches[1];
            return $this->request(URL::fromString($playerURL))->getContent();
        }
        return '';
    }

    /**
     * @param URL $url
     * @return string
     */
    private function extractVideoIdFromURL(URL $url): string
    {
        if (preg_match($this->urlRegExPatterns['full'], $url->getValue(), $matches)) {
            return $matches[1];
        }

        if (preg_match($this->urlRegExPatterns['short'], $url->getValue(), $matches)) {
            return $matches[1];
        }

        if (preg_match($this->urlRegExPatterns['embed'], $url->getValue(), $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * @param URL $url
     * @return ResponseInterface
     * @throws BadResponseException
     * @throws TooManyRequestsException
     * @throws TransportExceptionInterface
     */
    private function request(URL $url): ResponseInterface
    {
        $options = [
            'verify_peer' => false,
            'verify_host' => false
        ];

        $response = $this->client->request(
            'GET',
            $url->getValue(),
            $options
        );

        if ($response->getStatusCode() === self::TOO_MANY_REQUESTS_HTTP_CODE) {
            throw new TooManyRequestsException();
        }

        if ($response->getStatusCode() !== self::SUCCESS_HTTP_CODE) {
            throw new BadResponseException();
        }

        return $response;
    }

}