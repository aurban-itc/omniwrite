<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Ai;

use OmniWrite\Ai\AiException;
use OmniWrite\Ai\ClaudeClient;
use OmniWrite\Ai\ContentAi;
use OmniWrite\Ai\OpenAiImageClient;
use OmniWrite\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AiClientsTest extends TestCase
{
    private const TOOL = ['name' => 'submit_post', 'description' => 'd', 'input_schema' => ['type' => 'object']];
    private const SITE = ['name' => 'Alpha', 'locale' => 'de', 'style_guide' => 'Ton X', 'company_facts' => 'Fakt Y'];

    public function testClaudeSendsKeyModelAndToolAndReturnsToolInput(): void
    {
        $http = (new FakeHttpClient())->pushToolUse(['a' => 1]);
        $out = (new ClaudeClient($http, 'sk-test', 'm-1', 'https://api.test/v1/messages'))->callTool('sys', 'usr', self::TOOL);
        self::assertSame(['a' => 1], $out);
        $req = $http->requests[0];
        self::assertSame('https://api.test/v1/messages', $req['url']);
        self::assertSame('sk-test', $req['headers']['x-api-key']);
        self::assertSame('m-1', $http->lastPayload()['model']);
        self::assertSame('submit_post', $http->lastPayload()['tools'][0]['name']);
    }

    public function testClaudeMapsErrorsToKeysWithoutLeakingProviderText(): void
    {
        foreach ([[401, 'err.ai_key_rejected'], [429, 'err.ai_busy'], [500, 'err.ai_failed'], [0, 'err.ai_unreachable']] as [$status, $key]) {
            $http = (new FakeHttpClient())->push($status, '{"error":{"message":"secret-provider-detail sk-test"}}');
            try {
                (new ClaudeClient($http, 'sk-test'))->callTool('s', 'u', self::TOOL);
                self::fail("HTTP $status hätte scheitern müssen");
            } catch (AiException $e) {
                self::assertSame($key, $e->getMessage());
            }
        }
    }

    public function testClaudeRetriesOnceWhenNoToolCallThenGivesUp(): void
    {
        $text = json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'hi']]]);
        $http = (new FakeHttpClient())->push(200, $text)->pushToolUse(['ok' => true]);
        self::assertSame(['ok' => true], (new ClaudeClient($http, 'k'))->callTool('s', 'u', self::TOOL));
        self::assertCount(2, $http->requests);

        $http = (new FakeHttpClient())->push(200, $text)->push(200, $text);
        $this->expectExceptionMessage('err.ai_unreadable');
        (new ClaudeClient($http, 'k'))->callTool('s', 'u', self::TOOL);
    }

    public function testMissingKeyFailsBeforeAnyRequest(): void
    {
        $http = new FakeHttpClient();
        try {
            (new ClaudeClient($http, ''))->callTool('s', 'u', self::TOOL);
            self::fail('erwartet');
        } catch (AiException $e) {
            self::assertSame('err.ai_not_configured', $e->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    public function testImageClientReturnsBytesAndMapsModeration(): void
    {
        $http = (new FakeHttpClient())->pushImage('RAW');
        self::assertSame('RAW', (new OpenAiImageClient($http, 'k'))->generate('p'));
        self::assertSame('Bearer k', $http->requests[0]['headers']['Authorization']);

        $http = (new FakeHttpClient())->push(400, '{"error":{"code":"moderation_blocked","message":"safety"}}');
        $this->expectExceptionMessage('err.ai_image_declined');
        (new OpenAiImageClient($http, 'k'))->generate('p');
    }

    public function testImageClientFallsBackWhenModelDoesNotExist(): void
    {
        $http = (new FakeHttpClient())
            ->push(400, '{"error":{"code":"invalid_value","message":"the model \'gpt-image-1\' does not exist"}}')
            ->pushImage('RAW');
        self::assertSame('RAW', (new OpenAiImageClient($http, 'k'))->generate('p'));
        self::assertCount(2, $http->requests);
        self::assertSame('gpt-image-1', json_decode($http->requests[0]['json'], true)['model']);
        self::assertSame('gpt-image-1.5', $http->lastPayload()['model']);
    }

    public function testImageClientFallsBackWhenOrganizationIsNotVerifiedForModel(): void
    {
        $http = (new FakeHttpClient())
            ->push(403, '{"error":{"code":"organization_verification_required","message":"Your organization must be verified to use the model gpt-image-1"}}')
            ->pushImage('RAW');
        self::assertSame('RAW', (new OpenAiImageClient($http, 'k'))->generate('p'));
        self::assertCount(2, $http->requests);
    }

    public function testContentAiSanitizesOutputAndBuildsPromptWithSiteContext(): void
    {
        $http = (new FakeHttpClient())->pushToolUse(FakeHttpClient::post());
        $content = new ContentAi(new ClaudeClient($http, 'k'));
        $fields = $content->generatePost(self::SITE, ['title' => 'Thema', 'keywords' => 'k1', 'notes' => 'N'], 'kurz', ['Alt 1']);
        self::assertStringNotContainsString('script', $fields['body_html']);
        self::assertSame('ki-titel', $fields['slug']);
        $payload = $http->lastPayload();
        self::assertStringContainsString('Ton X', $payload['system']);
        self::assertStringContainsString('Fakt Y', $payload['system']);
        self::assertStringContainsString('German', $payload['system']);
        self::assertStringContainsString('Alt 1', $payload['messages'][0]['content']);
        self::assertStringContainsString('TOPIC: Thema', $payload['messages'][0]['content']);
    }

    public function testEmptyArticleIsRejected(): void
    {
        $http = (new FakeHttpClient())->pushToolUse(['title' => 'T', 'body_html' => '<script>x</script>'] + FakeHttpClient::post());
        $this->expectExceptionMessage('err.ai_empty');
        (new ContentAi(new ClaudeClient($http, 'k')))->generatePost(self::SITE, ['title' => 'T'], '', []);
    }
}
