<?php
namespace Intersect\SqlAssistant;

/**
 * Thin adapter between the module and the GenAI service REDCap has configured: the system-level
 * configuration, or the project's override when called in a project.
 * The module deliberately has no AI endpoint configuration of its own.
 *
 * NOTE: REDCap's AI classes are NOT part of the documented External Module framework API, so they
 * may change between REDCap versions. Every touch-point with them lives in this file, guarded by
 * class_exists/method_exists checks, so that a change in core only needs fixing in one place.
 */
class AIClient
{
	const SERVICE_OPENAI = 1; // Azure OpenAI or an OpenAI-compatible endpoint
	const SERVICE_GEMINI = 2;
	const MAX_OUTPUT_TOKENS = 4096;
	const LOG_CALL_TYPE = 'SqlAssistant'; // "type" recorded in redcap_ai_log
	const CHARS_PER_TOKEN = 4; // estimate used when the service does not report usage

	private $tokensUsed = 0;

	/** Tokens used by all calls made through this client: as reported by the service, or estimated. */
	public function getTokensUsed(): int
	{
		return $this->tokensUsed;
	}

	/** Add a call's usage: the service's reported total if there is one, otherwise ~4 characters per token. */
	private function addUsage($reportedTotal, string $sent, string $received): void
	{
		if (is_numeric($reportedTotal) && (int)$reportedTotal > 0) {
			$this->tokensUsed += (int)$reportedTotal;
		} elseif (trim($received) !== '') {
			$this->tokensUsed += (int)ceil((mb_strlen($sent) + mb_strlen($received)) / self::CHARS_PER_TOKEN);
		}
	}

	/**
	 * Whether a GenAI service is configured and enabled in the current context.
	 *  - In a project (PROJECT_ID defined): the project's override, else the default for all projects.
	 *  - Outside a project (e.g. the Control Center): the system-level configuration.
	 * Callers handling a project request must make sure PROJECT_ID is defined (see SqlAssistant::generate),
	 * otherwise core would silently use the system-level configuration instead of the project's.
	 */
	public function isAvailable(): bool
	{
		if (!class_exists('\AI') || !method_exists('\AI', 'isServiceDetailsSet')) return false;
		try {
			return (bool)\AI::isServiceDetailsSet();
		} catch (\Throwable $e) {
			return false;
		}
	}

	/** Short name of the configured service type, for logging. */
	public function getServiceName(): string
	{
		if (!$this->isAvailable()) return '';
		$type = \AI::getServiceAttributes()['service_enabled'] ?? null;
		return $type == self::SERVICE_OPENAI ? 'openai' : ($type == self::SERVICE_GEMINI ? 'gemini' : '');
	}

	/**
	 * Send a system prompt + user message and return the model's text reply.
	 *
	 * @throws \Exception with a user-presentable message on failure.
	 */
	public function complete(string $systemPrompt, string $userMessage): string
	{
		if (!$this->isAvailable()) {
			throw new \Exception(defined('PROJECT_ID') ? 'GenAI is not configured or enabled for this project.' : 'No system-level GenAI service is configured.');
		}
		$attr = \AI::getServiceAttributes();
		switch ($attr['service_enabled'] ?? null) {
			case self::SERVICE_OPENAI:
				$text = $this->completeOpenAI($attr['service_details'], $systemPrompt, $userMessage);
				$this->logCall(\AI::$serviceAzureOpenAI ?? 'AzureOpenAI', $systemPrompt, $userMessage, $text);
				return $text;
			case self::SERVICE_GEMINI:
				$text = $this->completeGemini($attr['service_details'], $systemPrompt, $userMessage);
				$this->logCall(\AI::$serviceGemini ?? 'Gemini', $systemPrompt, $userMessage, $text);
				return $text;
			default: throw new \Exception('The configured GenAI service is not supported by SQL Assistant.');
		}
	}

	/**
	 * Record the call in REDCap's own GenAI usage log (redcap_ai_log), as core does for its AI features,
	 * so the module's usage appears alongside them. Core stores only counts (characters and words sent and
	 * received), never the text. A logging failure never blocks the request.
	 *
	 * @param string $service core's label for the service (\AI::$serviceAzureOpenAI / \AI::$serviceGemini)
	 */
	private function logCall(string $service, string $system, string $user, string $response): void
	{
		if (!method_exists('\AI', 'logApiCall') || !defined('UI_ID')) return;
		try {
			\AI::logApiCall($service, self::LOG_CALL_TYPE, $system . "\n\n" . $user, $response,
				defined('PROJECT_ID') ? PROJECT_ID : null);
		} catch (\Throwable $e) {
			// Usage logging is best-effort
		}
	}

	/* ---------- Azure OpenAI / OpenAI-compatible ---------- */

	private function completeOpenAI(array $details, string $system, string $user): string
	{
		$class = '\Vanderbilt\REDCap\Classes\OpenAI\OpenAi';
		if (!class_exists($class)) throw new \Exception('REDCap\'s OpenAI client class could not be found.');

		// Core stores the deployment/model name in the "api version" slot (it sends it as "model").
		[$endpointUrl, $apiKey, $apiVersion] = $details;
		$client = new $class($apiKey, $endpointUrl);
		$raw = $client->chat([
			'model' => $apiVersion,
			'messages' => [
				['role' => 'system', 'content' => $system],
				['role' => 'user', 'content' => $user],
			],
			'max_completion_tokens' => self::MAX_OUTPUT_TOKENS,
		], $apiVersion);

		$decoded = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($decoded)) {
			throw new \Exception('No response was returned from the AI service.');
		}
		if (isset($decoded['error'])) {
			$err = $decoded['error'];
			$msg = is_array($err) ? (($err['code'] ?? '') . ': ' . ($err['message'] ?? 'unknown error')) : (string)$err;
			throw new \Exception('AI service error ' . $msg);
		}
		// Deliberately NOT passed through filter_tags(): logic such as "[a] < 5 and [b] > 2" looks like an HTML tag.
		$text = $decoded['choices'][0]['message']['content'] ?? '';
		$this->addUsage($decoded['usage']['total_tokens'] ?? null, $system . $user, (string)$text);
		if (trim((string)$text) === '') throw new \Exception('The AI service returned an empty response.');
		return (string)$text;
	}

	/* ---------- Google Gemini ---------- */

	private function completeGemini(array $details, string $system, string $user): string
	{
		$class = '\Vanderbilt\REDCap\Classes\GeminiAI\GeminiAi';
		if (!class_exists($class)) throw new \Exception('REDCap\'s Gemini client class could not be found.');

		[$apiKey, $apiModel, $apiVersion] = $details;
		$client = new $class($apiKey, $apiModel, $apiVersion);
		$raw = $client->chat([
			'systemInstruction' => ['parts' => [['text' => $system]]],
			'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
			'generationConfig' => [
				'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
				'responseMimeType' => 'application/json',
			],
		]);

		$decoded = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($decoded)) {
			throw new \Exception('No response was returned from the Gemini service.');
		}
		if (isset($decoded['error'])) {
			throw new \Exception('Gemini error ' . ($decoded['error']['code'] ?? '') . ': ' . ($decoded['error']['message'] ?? 'unknown error'));
		}
		$text = '';
		foreach ($decoded['candidates'][0]['content']['parts'] ?? [] as $part) {
			$text .= $part['text'] ?? '';
		}
		$this->addUsage($decoded['usageMetadata']['totalTokenCount'] ?? null, $system . $user, $text);
		if (trim($text) === '') throw new \Exception('The Gemini service returned an empty response.');
		return $text;
	}
}
