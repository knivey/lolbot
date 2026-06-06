<?php

namespace Tests\Imgur;

use PHPUnit\Framework\TestCase;

class ParsePostDataJsonTest extends TestCase
{
    public function testParsesValidJson(): void
    {
        $data = ['id' => 'AqeT58Y', 'title' => 'Froggy Friday', 'image_count' => 30, 'view_count' => 7570, 'point_count' => 261, 'is_album' => true, 'account' => ['username' => 'TestUser']];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $encoded = htmlspecialchars($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<html><body><div postDataJSON="' . $encoded . '"></div></body></html>';

        $result = \scripts\imgur\imgur::parsePostDataJson($html);
        $this->assertNotNull($result);
        $this->assertSame('AqeT58Y', $result['id']);
        $this->assertSame('Froggy Friday', $result['title']);
        $this->assertSame(30, $result['image_count']);
        $this->assertSame(7570, $result['view_count']);
        $this->assertSame(261, $result['point_count']);
    }

    public function testReturnsNullWhenNotFound(): void
    {
        $html = '<html><body>no data here</body></html>';
        $result = \scripts\imgur\imgur::parsePostDataJson($html);
        $this->assertNull($result);
    }

    public function testHandlesSingleQuoteEscapes(): void
    {
        $data = ['id' => 'abc', 'title' => "it's a test"];
        $json = json_encode($data);
        $encoded = str_replace("\\'", "'", htmlspecialchars($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html = '<html><body><div postDataJSON="' . $encoded . '"></div></body></html>';

        $result = \scripts\imgur\imgur::parsePostDataJson($html);
        $this->assertNotNull($result);
        $this->assertSame("it's a test", $result['title']);
    }
}
