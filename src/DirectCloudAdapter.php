<?php

namespace GNOffice\FlysystemDirectCloud;

use GNOffice\DirectCloud\Exceptions\BadRequest;
use GNOffice\Directcloud\Client;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\FilesystemException;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Throwable;

class DirectCloudAdapter implements FilesystemAdapter
{
    private PathPrefixer $prefixer;
    protected MimeTypeDetector $mimeTypeDetector;

    public function __construct(
        private Client $client,
        string $prefix = '',
        ?MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->prefixer         = new PathPrefixer($prefix);
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
    }

    public function fileExists(string $path): bool
    {
        $location = $this->applyPathPrefix($path);

        try {
            $fileSeq = $this->client->getFileSeq($location);

            return ! is_null($fileSeq);
        } catch (BadRequest) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        $location = $this->applyPathPrefix($path);

        try {
            $node = $this->client->getFolderNode($location, true);

            return ! is_null($node);
        } catch (BadRequest) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->uploadFile($location, $contents);
        } catch (BadRequest $exception) {
            throw UnableToWriteFile::atLocation($location, $exception->getMessage(), $exception);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        try {
            $this->client->uploadFile($location, $contents);
        } catch (BadRequest $exception) {
            throw UnableToWriteFile::atLocation($location, $exception->getMessage(), $exception);
        }
    }

    public function read(string $path): string
    {
        $object = $this->readStream($path);

        $contents = stream_get_contents($object);
        fclose($object);

        unset($object);

        return $contents;
    }

    public function readStream(string $path)
    {
        $location = $this->applyPathPrefix($path);

        try {
            $stream = $this->client->downloadFile($location);
        } catch (BadRequest $exception) {
            throw UnableToReadFile::fromLocation($location, $exception->getMessage(), $exception);
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        // TODO: Implement delete() method.
        dd($path);
    }

    public function deleteDirectory(string $path): void
    {
        $location = $this->applyPathPrefix($path);
        try {
            $this->client->deleteFolder($location);
        } catch (BadRequest $exception) {
            throw UnableToDeleteDirectory::atLocation($location, $exception->getMessage());
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->applyPathPrefix($path);
        try {
            $this->client->createFolder($location);
        } catch (BadRequest $exception) {
            throw UnableToCreateDirectory::atLocation($location, $exception->getMessage());
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // TODO: Implement setVisibility() method.
        dd($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        // TODO: Implement visibility() method.
        dd($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes(
            $path,
            null,
            null,
            null,
            $this->mimeTypeDetector->detectMimeTypeFromPath($path)
        );
    }

    public function lastModified(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->client->getFileInfo($location);
        } catch (BadRequest $exception) {
            throw UnableToRetrieveMetadata::lastModified($location, $exception->getMessage());
        }

        $timestamp = (isset($response['datetime'])) ? strtotime($response['datetime']) : null;

        return new FileAttributes(
            $path,
            null,
            null,
            $timestamp
        );
    }

    public function fileSize(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->client->getFileInfo($location);
        } catch (BadRequest $exception) {
            throw UnableToRetrieveMetadata::fileSize($location, $exception->getMessage());
        }

        return new FileAttributes(
            $path,
            $response['size'] ?? null
        );
    }

    public function listContents(string $path, bool $deep): iterable
    {
        // TODO: Implement listContents() method.
        dd($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        // TODO: Implement move() method.
        dd($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        // TODO: Implement copy() method.
        dd($source, $destination, $config);
    }

    public function createDirectoryLink(string $path, $expirationDate, $password): string
    {
        $location = $this->applyPathPrefix($path);

        return $this->client->createLink('folder', $location, $expirationDate, $password);
    }

    public function createFileLink(string $path): string
    {
        return 'test';
    }

    protected function applyPathPrefix($path): string
    {
        return '/'.trim($this->prefixer->prefixPath($path), '/');
    }
}
