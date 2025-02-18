<?php

namespace GNOffice\FlysystemDirectCloud\Test;

use GNOffice\DirectCloud\Client;
use GNOffice\FlysystemDirectCloud\DirectCloudAdapter;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;

class DirectCloudAdapterTest extends FilesystemAdapterTestCase
{

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new DirectCloudAdapter(new Client(new AccessKeyTokenProvider(getenv('DIRECTCLOUD_SERVICE'), getenv('DIRECTCLOUD_SERVICE_KEY'), getenv('DIRECTCLOUD_ACCESS_KEY'))));
    }
}