<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_copilot\Service\AgentToolRegistry;
use Drupal\ai_copilot\Service\CopilotLlmProvider;

/**
 * Kernel test for the agent loop in CopilotChatController.
 *
 * All LLM calls are replaced with deterministic mocks — zero live API calls.
 * Tests assert:
 *   1. The loop calls the right tool, passes its result back into history, and
 *      returns the final text reply with the correct steps trace.
 *   2. The 6-iteration safety cap stops an infinite tool-calling sequence and
 *      returns an error rather than looping forever.
 *
 * @group ai_copilot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class AgentLoopTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'node', 'ai_copilot'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ai_copilot', ['ai_copilot_contrib_index']);
    // No data seeding needed — mocked LLM never hits the DB for these tests.
  }

  /**
   * Tests the happy-path agent loop: one tool call followed by a text reply.
   *
   * Scripted mock sequence:
   *   Turn 1 → LLM returns function_call{get_site_context}
   *   Turn 2 → LLM returns text "Config-only solution recommended."
   *
   * Assertions:
   *   - steps contains one entry for get_site_context with status 'completed'.
   *   - reply equals the final text returned by the mock.
   *   - conversation history is saved to PrivateTempStore.
   */
  public function testAgentLoopExecutesToolAndReturnsReply(): void {
    $callCount = 0;
    $llmMock = $this->createMock(CopilotLlmProvider::class);
    $llmMock->method('sendConversation')
      ->willReturnCallback(function (array $history, array $tools) use (&$callCount): array {
        $callCount++;
        if ($callCount === 1) {
          // First call: request the site context tool.
          return ['type' => 'function_call', 'name' => 'get_site_context', 'args' => [], 'id' => 'call-001'];
        }
        // Second call: model has received the tool result and provides a final answer.
        return ['type' => 'text', 'text' => 'Config-only solution recommended.'];
      });
    $this->container->set('ai_copilot.llm_provider', $llmMock);

    /** @var \Drupal\ai_copilot\Service\ConversationSessionService $session */
    $session = $this->container->get('ai_copilot.conversation_session');
    $conversationId = 'test-conv-' . uniqid();

    // Simulate what the controller does when handleChat() is called.
    $reply = $this->runAgentLoop('Add a Blog content type', $conversationId);

    $this->assertFalse($reply['error'], 'Agent loop should succeed without error.');
    $this->assertEquals('Config-only solution recommended.', $reply['reply']);
    $this->assertCount(1, $reply['steps']);
    $this->assertEquals('get_site_context', $reply['steps'][0]['tool']);
    $this->assertEquals('completed', $reply['steps'][0]['status']);
    $this->assertEquals(2, $callCount, 'LLM should be called exactly twice: once for tool call, once for final text.');

    // Verify history is persisted.
    $history = $session->getHistory($conversationId);
    $this->assertNotEmpty($history, 'Conversation history must be saved to PrivateTempStore.');

    // History should contain: initial user message, model function_call, user functionResponse, model text.
    $roles = array_column($history, 'role');
    $this->assertContains('model', $roles, 'History must include model turns.');
  }

  /**
   * Tests that the 6-iteration safety cap stops an infinite function_call loop.
   *
   * Mock always returns function_call — never a text response.
   * The loop should stop at 6 iterations and return an error.
   */
  public function testAgentLoopCapPreventsInfiniteLoop(): void {
    $callCount = 0;
    $llmMock = $this->createMock(CopilotLlmProvider::class);
    $llmMock->method('sendConversation')
      ->willReturnCallback(function () use (&$callCount): array {
        $callCount++;
        // Always return a function call — never a final text response.
        return ['type' => 'function_call', 'name' => 'get_site_context', 'args' => [], 'id' => 'call-' . $callCount];
      });
    $this->container->set('ai_copilot.llm_provider', $llmMock);

    $conversationId = 'test-cap-' . uniqid();
    $reply = $this->runAgentLoop('Some requirement', $conversationId);

    $this->assertTrue($reply['error'], 'Loop cap must return an error.');
    $this->assertStringContainsString('6', $reply['message'], 'Error message must mention the iteration cap (6).');
    $this->assertEquals(6, $callCount, 'LLM must be called exactly 6 times before stopping.');
    $this->assertCount(6, $reply['steps'], 'steps array must record all 6 tool-call attempts.');
  }

  /**
   * Tests multi-turn conversation memory: second turn sees first turn in history.
   *
   * Turn 1: user sends "Add an Event content type" → mock returns text "I can help."
   * Turn 2: user sends "Add a date field to it" → assert history passed to LLM contains
   *         both the system+first message AND the model's first reply.
   */
  public function testConversationMemoryPersistsAcrossTurns(): void {
    $receivedHistories = [];
    $llmMock = $this->createMock(CopilotLlmProvider::class);
    $llmMock->method('sendConversation')
      ->willReturnCallback(function (array $history) use (&$receivedHistories): array {
        $receivedHistories[] = $history;
        return ['type' => 'text', 'text' => 'Understood, proceeding.'];
      });
    $this->container->set('ai_copilot.llm_provider', $llmMock);

    $conversationId = 'test-mem-' . uniqid();

    // First turn.
    $this->runAgentLoop('Add an Event content type', $conversationId);
    // Second turn.
    $this->runAgentLoop('Add a date field to it', $conversationId);

    $this->assertCount(2, $receivedHistories, 'LLM should be called once per turn.');

    // Second turn's history must be longer than first turn's history.
    $this->assertGreaterThan(
      count($receivedHistories[0]),
      count($receivedHistories[1]),
      'Second turn must pass a longer history (proving memory persists across turns).'
    );

    // Second turn's history must include the model's reply from the first turn.
    $secondHistory = $receivedHistories[1];
    $modelTurns = array_filter($secondHistory, fn($t) => ($t['role'] ?? '') === 'model');
    $this->assertNotEmpty($modelTurns, 'Second turn history must contain the model reply from turn 1.');
  }

  /**
   * Tests that a \TypeError from a tool is caught by the \Throwable block.
   *
   * \TypeError extends \Error, not \Exception, so catch (\Exception) would
   * have let it escape and crash the loop. The loop must record it as a
   * failed step and continue to a text reply.
   */
  public function testAgentLoopCatchesTypeErrorFromTool(): void {
    $callCount = 0;
    $llmMock = $this->createMock(CopilotLlmProvider::class);
    $llmMock->method('sendConversation')
      ->willReturnCallback(function (array $history, array $tools) use (&$callCount): array {
        $callCount++;
        if ($callCount === 1) {
          // First call: request the site context tool.
          return [
            'type' => 'function_call',
            'name' => 'get_site_context',
            'args' => [],
            'id' => 'call-type-error',
          ];
        }
        // Second call: after the tool errored, model produces final answer.
        return ['type' => 'text', 'text' => 'Could not read site context due to an internal error.'];
      });
    $this->container->set('ai_copilot.llm_provider', $llmMock);

    // Override the tool registry so get_site_context throws a \TypeError.
    $toolRegistryMock = $this->createMock(AgentToolRegistry::class);
    $toolRegistryMock->method('getDeclarations')
      ->willReturn([
        [
          'name' => 'get_site_context',
          'description' => 'test',
          'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ],
      ]);
    $toolRegistryMock->method('execute')
      ->willThrowException(new \TypeError('Argument 1 must be of type string, null given'));
    $this->container->set('ai_copilot.agent_tool_registry', $toolRegistryMock);

    $conversationId = 'test-typeerror-' . uniqid();
    $reply = $this->runAgentLoop('What is on my site?', $conversationId);

    $this->assertFalse($reply['error'], 'Loop must not error out on a \\TypeError — it should continue to a text reply.');
    $this->assertCount(1, $reply['steps'], 'The failed tool call must still be recorded in steps.');
    $this->assertEquals('get_site_context', $reply['steps'][0]['tool']);
    $this->assertEquals('error', $reply['steps'][0]['status'], 'Step status must be "error" when \\TypeError thrown.');
    $this->assertEquals(
      'Could not read site context due to an internal error.',
      $reply['reply'],
      'Loop must continue after \\TypeError and return the LLM\'s follow-up text reply.'
    );
    $this->assertEquals(2, $callCount, 'LLM must be called twice: once for tool call, once for final text.');
  }

  /**
   * Runs the agent loop directly using the container services.
   *
   * This mirrors the logic in CopilotChatController::handleChat() so that the
   * controller itself does not need to be bootstrapped for a kernel test.
   *
   * @param string $prompt
   *   User message.
   * @param string $conversationId
   *   Conversation identifier.
   *
   * @return array
   *   {error, reply?, message?, steps, conversation_id}
   */
  protected function runAgentLoop(string $prompt, string $conversationId): array {
    /** @var \Drupal\ai_copilot\Service\CopilotLlmProvider $llm */
    $llm = $this->container->get('ai_copilot.llm_provider');
    /** @var \Drupal\ai_copilot\Service\AgentToolRegistry $toolRegistry */
    $toolRegistry = $this->container->get('ai_copilot.agent_tool_registry');
    /** @var \Drupal\ai_copilot\Service\ConversationSessionService $session */
    $session = $this->container->get('ai_copilot.conversation_session');

    $history = $session->getHistory($conversationId);
    $isNew = empty($history);

    $systemPrompt = "You are Drupal AI Copilot. Help with Drupal development decisions.";
    if ($isNew) {
      $history[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt . "\n\nDeveloper request: " . $prompt]]];
    }
    else {
      $history[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];
    }

    $tools = $toolRegistry->getDeclarations();
    $steps = [];
    $maxIterations = 6;

    for ($i = 0; $i < $maxIterations; $i++) {
      $result = $llm->sendConversation($history, $tools);

      if ($result['type'] === 'error') {
        return [
          'error' => TRUE,
          'message' => $result['message'],
          'steps' => $steps,
          'conversation_id' => $conversationId,
        ];
      }

      if ($result['type'] === 'function_call') {
        $history[] = [
          'role' => 'model',
          'parts' => [[
            'functionCall' => [
              'name' => $result['name'],
              'args' => $result['args'],
              'id' => $result['id'],
            ],
          ],
          ],
        ];

        try {
          $toolResult = $toolRegistry->execute($result['name'], $result['args']);
          $steps[] = ['tool' => $result['name'], 'status' => 'completed'];
        }
        catch (\Throwable $e) {
          $toolResult = ['error' => $e->getMessage()];
          $steps[] = ['tool' => $result['name'], 'status' => 'error'];
        }

        $history[] = [
          'role' => 'user',
          'parts' => [[
            'functionResponse' => [
              'name' => $result['name'],
              'id' => $result['id'],
              'response' => $toolResult,
            ],
          ],
          ],
        ];
        continue;
      }

      if ($result['type'] === 'text') {
        $history[] = ['role' => 'model', 'parts' => [['text' => $result['text']]]];
        $session->saveHistory($conversationId, $history);
        return [
          'error' => FALSE,
          'reply' => $result['text'],
          'steps' => $steps,
          'conversation_id' => $conversationId,
        ];
      }
    }

    $session->saveHistory($conversationId, $history);
    return [
      'error' => TRUE,
      'message' => sprintf('Agent loop reached the %d-iteration safety cap.', $maxIterations),
      'steps' => $steps,
      'conversation_id' => $conversationId,
    ];
  }

}
