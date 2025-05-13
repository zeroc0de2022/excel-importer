<?php
namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UploadTest extends TestCase
{
    public function test_file_upload_requires_auth()
    {
        $response = $this->post('/upload');
        $response->assertStatus(401);
    }

    public function test_upload_validation()
    {
        $file = UploadedFile::fake()->create('file.txt', 100);
        $response = $this->post('/upload', ['file' => $file], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW'   => 'secret'
        ]);
        $response->assertSessionHasErrors('file');
    }
}
