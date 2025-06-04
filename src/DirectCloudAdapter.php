<?php

namespace GNOffice\FlysystemDirectCloud;

use Exception;
use GNOffice\DirectCloud\Exceptions\BadRequest;
use GNOffice\Directcloud\Client;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\FilesystemException;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;

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

    public function getClient(): Client
    {
        return $this->client;
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
            return $this->getFolderNodeAndSeq($location) !== null;
        } catch (BadRequest) {
            return false;
        }
    }

    public function write(string $path, $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        if ($node = $this->getFolderNodeAndSeq(dirname($location))['node']) {
            $this->client->upload($node, $contents, basename($location));
        } else {
            $this->createDirectory(dirname($location), $config);

            $this->write($path, $contents, $config);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        if ($this->getFolderNodeAndSeq(dirname($location))['node']) {
            $this->client->upload($this->getFolderNodeAndSeq(dirname($location))['node'], $contents,
                basename($location));
        } else {
            $this->createDirectory(dirname($location), $config);

            $this->writeStream($path, $contents, $config);
        }
    }

    public function read(string $path): string
    {
        $location = $this->applyPathPrefix($path);
        $fileSeq  = $this->getFileSeq($location);

        try {
            $contents = $this->client->download($fileSeq);
        } catch (BadRequest $exception) {
            throw UnableToReadFile::fromLocation($location, $exception->getMessage(), $exception);
        }
        return $contents;
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);

        $resource = tmpfile();
        fwrite($resource, $contents);
        rewind($resource);

        return $resource;
    }

    public function delete(string $path): void
    {
        $location      = $this->applyPathPrefix($path);
        $parentFolderNode = $this->getFolderNodeAndSeq(dirname($location))['node'];
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
        $node = $this->getFolderNodeAndSeq($location)['node'];
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
            if ($deepestFolderNode = $this->getFolderNodeAndSeq(dirname($location, $i))['node']) {
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

        $parentFolderNode = $this->getFolderNodeAndSeq(dirname($location))['node'];
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
        $parentFolderNode = $this->getFolderNodeAndSeq(dirname($location))['node'];
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

    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $location = $this->applyPathPrefix($path);
        $node = $this->getFolderNodeAndSeq($location)['node'];
        if (is_null($node)) {
            throw UnableToListContents::atLocation($location, $deep, new Exception('Directory not found.'));
        }

        $response = $this->client->getList($node);

        foreach ($response['folders'] as $folder) {
            $normalizedPath = ltrim($this->prefixer->stripDirectoryPrefix($folder['drive_path']), '/');

            if ($deep) {
                yield from $this->listContents($normalizedPath, $deep);
            }

            yield new DirectoryAttributes(
                $normalizedPath,
                null,
                (isset($folder['datetime_at'])) ? strtotime($folder['datetime_at']) : null
            );
        }

        // ファイルの一覧
        foreach ($response['files'] as $file) {
            $normalizedPath = ltrim($this->prefixer->stripDirectoryPrefix($location . '/' . $file['name']), '/');

            yield new FileAttributes(
                $normalizedPath,
                $file['size'] ?? null,
                null,
                (isset($file['datetime_at'])) ? strtotime($file['datetime_at']) : null,
                $this->mimeTypeDetector->detectMimeTypeFromPath($normalizedPath)
            );
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $srcNode = $this->getFolderNodeAndSeq(dirname($this->applyPathPrefix($source)))['node'];
        $dstNode = $this->getFolderNodeAndSeq(dirname($this->applyPathPrefix($destination)))['node'];
        $fileSeq = $this->getFileSeq($this->applyPathPrefix($source));

        $this->client->moveFile($dstNode, $srcNode, $fileSeq);

        if (basename($source) !== basename($destination)) {
            $this->client->renameFile($dstNode, $fileSeq, basename($destination));
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $srcNode = $this->getFolderNodeAndSeq(dirname($this->applyPathPrefix($source)))['node'];
        $dstNode = $this->getFolderNodeAndSeq(dirname($this->applyPathPrefix($destination)))['node'];
        $fileSeq = $this->getFileSeq($this->applyPathPrefix($source));

        $response = $this->client->copyFile($dstNode, $srcNode, $fileSeq);

        if (basename($source) !== basename($destination)) {
            $this->client->renameFile($dstNode, $response['data']['new_file_seq'], basename($destination));
        }
    }

    protected function applyPathPrefix($path): string
    {
        return '/'.trim($this->prefixer->prefixPath($path), '/');
    }

    protected function getFileSeq($path)
    {
        if ($deepestFolderNode = $this->getFolderNodeAndSeq(dirname($path))['node']) {
            $fileName = basename($path);

            $files = $this->client->getList($deepestFolderNode)['files'];

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

    public function getFolderNodeAndSeq($path): ?array
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

            $folders = $this->client->getList($node)['folders'];

            if ($folders) {
                if (($list = array_search($location, array_column($folders, 'drive_path'))) !== false) {
                    $node = $folders[$list]['node'];
                    if ($path === $location) {
                        return [
                            'node' => $folders[$list]['node'],
                            'seq'  => $folders[$list]['dir_seq'],
                        ];
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
