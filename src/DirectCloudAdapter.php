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
use League\Flysystem\UnableToSetVisibility;
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
            $fileSeq = $this->getFileSeq($location);

            return ! is_null($fileSeq);
        } catch (BadRequest) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        $location = $this->applyPathPrefix($path);

        try {
            $node = $this->getFolderNode($location);

            return ! is_null($node);
        } catch (BadRequest) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);
        $node     = $this->getFolderNode($location);

        try {
            $this->client->upload($node, $contents);
        } catch (BadRequest $exception) {
            throw UnableToWriteFile::atLocation($location, $exception->getMessage(), $exception);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);
        $node     = $this->getFolderNode($location);

        try {
            $this->client->upload($node, $contents);
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
        $fileSeq  = $this->getFileSeq($location);

        try {
            $stream = $this->client->download($fileSeq);
        } catch (BadRequest $exception) {
            throw UnableToReadFile::fromLocation($location, $exception->getMessage(), $exception);
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        $location      = $this->applyPathPrefix($path);
        $parentFolderNode = $this->getFolderNode(dirname($location));
        $fileSeq       = $this->getFileSeq($location);

        try {
            $this->client->deleteFile($parentFolderNode, $fileSeq);
        } catch (BadRequest $exception) {
            throw UnableToDeleteFile::atLocation($location, $exception->getMessage());
        }
    }

    public function deleteDirectory(string $path): void
    {
        $location = $this->applyPathPrefix($path);
        $node     = $this->getFolderNode($location);
        try {
            $this->client->deleteFolder($node);
        } catch (BadRequest $exception) {
            throw UnableToDeleteDirectory::atLocation($location, $exception->getMessage());
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        $parts = explode('/', $location);
        $parts = array_values(array_filter($parts));

        // 作成済みの最も深いフォルダのノードを取得
        for ($i = 1, $iMax = count($parts); $i <= $iMax; $i++) {
            if ($deepestFolderNode = $this->getFolderNode(dirname($location, $i))) {
                break;
            }
        }

        for ($j = $i; $j > 0; $j--) {
            try {
                $response = $this->client->createFolder($deepestFolderNode, $parts[$iMax - $j]);
            } catch (BadRequest $exception) {
                throw UnableToCreateDirectory::atLocation($location, $exception->getMessage());
            }

            $deepestFolderNode = $response['node'];
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Adapter does not support visibility controls.');
    }

    public function visibility(string $path): FileAttributes
    {
        // Noop
        return new FileAttributes($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

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

        $parentFolderNode = $this->getFolderNode(dirname($location));
        if (is_null($parentFolderNode)) {
            throw UnableToRetrieveMetadata::lastModified($location, 'File not found.');
        }

        $fileSeq = $this->getFileSeq($location);
        if (is_null($fileSeq)) {
            throw UnableToRetrieveMetadata::lastModified($location, 'File not found.');
        }

        try {
            $response = $this->client->getFileInfo($parentFolderNode, $fileSeq);
        } catch (BadRequest $exception) {
            throw UnableToRetrieveMetadata::lastModified($location, $exception->getMessage());
        }

        $timestamp = (isset($response['result']['datetime'])) ? strtotime($response['result']['datetime']) : null;

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
        $parentFolderNode = $this->getFolderNode(dirname($location));
        if (is_null($parentFolderNode)) {
            throw UnableToRetrieveMetadata::fileSize($location, 'File not found.');
        }

        $fileSeq = $this->getFileSeq($location);
        if (is_null($fileSeq)) {
            throw UnableToRetrieveMetadata::fileSize($location, 'File not found.');
        }

        try {
            $response = $this->client->getFileInfo($parentFolderNode, $fileSeq);
        } catch (BadRequest $exception) {
            throw UnableToRetrieveMetadata::fileSize($location, $exception->getMessage());
        }

        return new FileAttributes(
            $path,
            $response['result']['size'] ?? null
        );
    }

    public function listContents(string $path, bool $deep): iterable
    {
        // 指定したパスに含まれるディレクトリとファイルの一覧を取得する
        // TODO: Implement listContents() method.
//        $timestamp = (isset($response['server_modified'])) ? strtotime($response['server_modified']) : null;
//        if ($response['.tag'] === 'folder') {
//            $normalizedPath = ltrim($this->prefixer->stripDirectoryPrefix($response['path_display']), '/');
//
//            return new DirectoryAttributes(
//                $normalizedPath,
//                null,
//                $timestamp
//            );
//        }
//
//        $normalizedPath = ltrim($this->prefixer->stripPrefix($response['path_display']), '/');
//
//        return new FileAttributes(
//            $normalizedPath,
//            $response['size'] ?? null,
//            null,
//            $timestamp,
//            $this->mimeTypeDetector->detectMimeTypeFromPath($normalizedPath)
//        );

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

    protected function getFileSeq($path)
    {
        $parts = explode('/', $path);
        $parts = array_values(array_filter($parts));

        if ($deepestFolderNode = $this->getFolderNode(dirname($path, 1), true)) {
            $fileName = $parts[array_key_last($parts)];

            $files = $this->client->getFileList($deepestFolderNode);

            if ($files) {
                if (($list = array_search($fileName, array_column($files, 'name'))) !== false) {
                    return $files[$list]['file_seq'];
                }

                // ファイルが見つからない
                return null;
            }

            // 指定のフォルダにファイルがない
            return null;
        }

        // フォルダが見つからない
        return null;
    }

    protected function getFolderNode($path)
    {
        $path  = rtrim($path, '/');
        $parts = explode('/', $path);
        $parts = array_values(array_filter($parts));

        $directoryParts = [];
        $node           = '1{2';

        foreach ($parts as $directory) {
            if ($directory === '.' || $directory === '') {
                continue;
            }

            $directoryParts[] = $directory;
            $directoryPath    = implode('/', $directoryParts);
            $location         = '/'.$directoryPath;

            $folders = $this->client->getFolderList($node);

            if ($folders) {
                if (($list = array_search($location, array_column($folders, 'drive_path'))) !== false) {
                    $node = $folders[$list]['node'];
                    if ($path === $location) {
                        return $node;
                    }
                } elseif ($path === $location) {
                    return null;
                }
            } else {
                // フォルダが存在しない
                return null;
            }
        }

        return null;
    }
}
