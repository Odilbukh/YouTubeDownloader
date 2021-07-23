<?php
namespace AnyDownloader\YouTubeDownloader\Model;

use AnyDownloader\DownloadManager\Model\FetchedResource;

class YouTubeFetchedResource extends FetchedResource
{
    /**
     * @return string
     */
    public function getExtSource(): string
    {
        return 'youtube';
    }
}