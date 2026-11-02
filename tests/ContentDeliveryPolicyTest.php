<?php

namespace hrzg\filefly\tests;

use hrzg\filefly\components\ContentDeliveryPolicy;
use PHPUnit\Framework\TestCase;
use yii\web\Response;

class ContentDeliveryPolicyTest extends TestCase
{
    public function testActiveContentByMimeType(): void
    {
        $policy = new ContentDeliveryPolicy();

        $this->assertTrue($policy->isActiveContent('file.bin', 'image/svg+xml'));
        $this->assertTrue($policy->isActiveContent('file.bin', 'text/html; charset=utf-8'));
        $this->assertTrue($policy->isActiveContent('file.bin', 'application/rss+xml'));
        $this->assertFalse($policy->isActiveContent('file.bin', 'image/png'));
    }

    public function testActiveContentByExtension(): void
    {
        $policy = new ContentDeliveryPolicy();

        // the extension wins even if the stored MIME type looks harmless
        $this->assertTrue($policy->isActiveContent('folder/logo.svg', 'image/png'));
        $this->assertTrue($policy->isActiveContent('index.HTML'));
        $this->assertTrue($policy->isActiveContent('feed.xml'));
        $this->assertFalse($policy->isActiveContent('photo.jpg', 'image/jpeg'));
        $this->assertFalse($policy->isActiveContent('document.pdf', 'application/pdf'));
    }

    public function testHeadersForActiveContent(): void
    {
        $response = new Response(['charset' => 'UTF-8']);
        $policy = new ContentDeliveryPolicy();

        $this->assertTrue($policy->apply($response, 'logo.svg', 'image/svg+xml'));
        $response->trigger(Response::EVENT_BEFORE_SEND);

        $headers = $response->getHeaders();
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $headers->get('Content-Security-Policy'));
    }

    public function testApplicationCspIsKept(): void
    {
        $response = new Response(['charset' => 'UTF-8']);
        // handler registered by the application config, runs first
        $response->on(Response::EVENT_BEFORE_SEND, static function ($event) {
            $event->sender->getHeaders()->set('Content-Security-Policy', "frame-ancestors 'self';");
        });

        (new ContentDeliveryPolicy())->apply($response, 'logo.svg', 'image/svg+xml');
        $response->trigger(Response::EVENT_BEFORE_SEND);

        $csp = $response->getHeaders()->get('Content-Security-Policy', null, false);
        $this->assertCount(2, $csp);
        $this->assertStringContainsString('sandbox', $csp[1]);
    }

    public function testHeadersForPassiveContent(): void
    {
        $response = new Response(['charset' => 'UTF-8']);
        $policy = new ContentDeliveryPolicy();

        $this->assertFalse($policy->apply($response, 'photo.jpg', 'image/jpeg'));
        $response->trigger(Response::EVENT_BEFORE_SEND);

        $headers = $response->getHeaders();
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertFalse($headers->has('Content-Security-Policy'));
    }

    public function testDisabled(): void
    {
        $response = new Response(['charset' => 'UTF-8']);
        $policy = new ContentDeliveryPolicy(['enabled' => false]);

        $this->assertFalse($policy->apply($response, 'logo.svg', 'image/svg+xml'));
        $response->trigger(Response::EVENT_BEFORE_SEND);

        $this->assertFalse($response->getHeaders()->has('X-Content-Type-Options'));
        $this->assertFalse($response->getHeaders()->has('Content-Security-Policy'));
    }
}
