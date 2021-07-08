<?php
namespace AnyDownloader\YouTubeDownloader;

use AnyDownloader\DownloadManager\Exception\NothingToExtractException;
use AnyDownloader\DownloadManager\Exception\NotValidUrlException;
use AnyDownloader\DownloadManager\Handler\BaseHandler;
use AnyDownloader\DownloadManager\Model\Attribute;
use AnyDownloader\DownloadManager\Model\Attribute\AuthorAttribute;
use AnyDownloader\DownloadManager\Model\Attribute\HashtagsAttribute;
use AnyDownloader\DownloadManager\Model\Attribute\TitleAttribute;
use AnyDownloader\DownloadManager\Model\Attribute\TextAttribute;
use AnyDownloader\DownloadManager\Model\FetchedResource;
use AnyDownloader\DownloadManager\Model\ResourceItem\Audio\AudioMP4ResourceItem;
use AnyDownloader\DownloadManager\Model\ResourceItem\ResourceItemFactory;
use AnyDownloader\DownloadManager\Model\ResourceItem\Text\XMLResourceItem;
use AnyDownloader\DownloadManager\Model\ResourceItem\Video\MP4ResourceItem;
use AnyDownloader\DownloadManager\Model\URL;
use AnyDownloader\YouTubeDownloader\Model\YouTubeFetchedResource;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YouTubeHandler extends BaseHandler
{
    /**
     * @var string[]
     */
    protected $urlRegExPatterns = [
        'full' => '/(\/\/|www\.)youtube\.[a-z]+\/watch\?v\=[a-zA-Z0-9-]+/',
        'short' => '/(\/\/|www\.)youtu\.be\/[a-zA-Z0-9-]+/',
        'embed' => '/(\/\/|www\.)youtube\.[a-z]+\/embed\/[a-zA-Z0-9-]+/',
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
     * @throws NothingToExtractException
     * @throws NotValidUrlException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface|TransportExceptionInterface
     */
    public function fetchResource(URL $url): FetchedResource
    {
        $vId = $this->extractVideoIdFromURL($url);
        if (empty($vId)) {
            throw new NotValidUrlException();
        }
        $data = $this->getDataFromYoutube($vId);
        $ytFetchedResource = new YouTubeFetchedResource($url);
        if ($data->videoDetails) {
            if ($data->videoDetails->title) {
                $ytFetchedResource->addAttribute(new TitleAttribute($data->videoDetails->title));
            }
            if ($data->videoDetails->text) {
                $ytFetchedResource->addAttribute(new TextAttribute($data->videoDetails->text));
            }
            if ($data->videoDetails->keywords) {
                $ytFetchedResource->addAttribute(HashtagsAttribute::fromStringArray($data->videoDetails->keywords));
            }
            if ($data->videoDetails->channelId) {
                $ytFetchedResource->addAttribute(
                    new AuthorAttribute(
                        $data->videoDetails->channelId ?? null,
                        $data->videoDetails->author ?? null,
                        $data->videoDetails->author ?? null
                    )
                );
            }
            if ($data->videoDetails->thumbnail && count($data->videoDetails->thumbnail->thumbnails)) {
                $thumbData = end($data->videoDetails->thumbnail->thumbnails);
                $thumbnail = ResourceItemFactory::fromURL(
                    URL::fromString($thumbData->url),
                    $thumbData->width . 'x' . $thumbData->height
                );
                $ytFetchedResource->setImagePreview($thumbnail);
                $ytFetchedResource->addItem($thumbnail);
            }
        }

        if ($data->captions) {
            if($data->captions->playerCaptionsTracklistRenderer->captionTracks) {
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

        if ($data->streamingData ) {
            foreach ($data->streamingData->formats as $item) {
                if ($item->url) {
                    if (strpos($item->mimeType, MP4ResourceItem::MIMEType()) !== false) {
                        $url = URL::fromString($item->url);
                        $quality = $item->qualityLabel ?? $item->bitrate;
                        $resItem = new MP4ResourceItem($url, $quality);
                        $ytFetchedResource->addItem($resItem);
                        $ytFetchedResource->setVideoPreview($resItem);
                    }
                }
            }
            foreach ($data->streamingData->adaptiveFormats as $item) {
                if ($item->url) {
                    if (strpos($item->mimeType, AudioMP4ResourceItem::MIMEType()) !== false) {
                        $url = URL::fromString($item->url);
                        $quality = $item->qualityLabel ?? $item->bitrate;
                        $ytFetchedResource->addItem(new AudioMP4ResourceItem($url, $quality));
                    }
                }
            }
        }

        return $ytFetchedResource;
    }

    /**
     * @param string $vId
     * @return \stdClass
     * @throws NothingToExtractException
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws ClientExceptionInterface|TransportExceptionInterface
     */
    private function getDataFromYoutube(string $vId): \stdClass
    {
        $resp = $this->client->request(
            'GET',
            'https://www.youtube.com/get_video_info?video_id=' . $vId . '&eurl=https%3A%2F%2Fyoutube.googleapis.com%2Fv%2F' . $vId . '&html5=1&c=TVHTML5&cver=6.20180913',
            [
                'verify_peer' => false,
                'verify_host' => false
            ]
        );
        $content = urldecode($resp->getContent());
        if (strpos($content, 'responseContext') === false) {
            throw new NothingToExtractException();
        }
        $content = '{"responseContext' . explode('{"responseContext', $content)[1];
        $content = explode('}&', $content)[0] . '}';

        $content = json_decode($content);
        if (json_last_error()) {
            throw new NothingToExtractException();
        }
        return $content;
    }

    /**
     * @param URL $url
     * @return string
     */
    private function extractVideoIdFromURL(URL $url): string
    {
        preg_match($this->urlRegExPatterns['full'], $url->getValue(), $matches);
        if ($matches) {
            return explode('=', $matches[0])[1];
        }

        preg_match($this->urlRegExPatterns['short'], $url->getValue(), $matches);
        if ($matches) {
            $parts = explode('/', $matches[0]);
            return end($parts);
        }

        preg_match($this->urlRegExPatterns['embed'], $url->getValue(), $matches);
        if ($matches) {
            $parts = explode('/', $matches[0]);
            return end($parts);
        }

        return '';
    }

}