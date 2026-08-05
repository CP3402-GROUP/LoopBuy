package ai

import (
	"context"
	"fmt"
	"net/http"
	"strings"
)

const qwenChatCompletionsPath = "/chat/completions"

// QwenChatConfig configures the DashScope OpenAI-compatible chat endpoint.
type QwenChatConfig struct {
	BaseURL             string
	APIKey              string
	Model               string
	Temperature         *float64
	MaxCompletionTokens int
	EnableThinking      *bool
}

// QwenChatModel implements ChatModel using DashScope's OpenAI-compatible API.
type QwenChatModel struct {
	rest                *restClient
	model               string
	temperature         *float64
	maxCompletionTokens int
	enableThinking      *bool
}

var _ ChatModel = (*QwenChatModel)(nil)

func NewQwenChatModel(config QwenChatConfig, client *http.Client) (*QwenChatModel, error) {
	config.APIKey = strings.TrimSpace(config.APIKey)
	config.Model = strings.TrimSpace(config.Model)
	if config.APIKey == "" {
		return nil, fmt.Errorf("qwen chat: API key is required")
	}
	if config.Model == "" {
		return nil, fmt.Errorf("qwen chat: model is required")
	}
	if config.MaxCompletionTokens < 0 {
		return nil, fmt.Errorf("qwen chat: max completion tokens must not be negative")
	}
	if config.Temperature != nil && (*config.Temperature < 0 || *config.Temperature >= 2) {
		return nil, fmt.Errorf("qwen chat: temperature must be in [0, 2)")
	}

	rest, err := newRESTClient(
		"qwen chat",
		config.BaseURL,
		client,
		"Authorization",
		"Bearer "+config.APIKey,
	)
	if err != nil {
		return nil, err
	}

	var temperature *float64
	if config.Temperature != nil {
		value := *config.Temperature
		temperature = &value
	}
	var enableThinking *bool
	if config.EnableThinking != nil {
		value := *config.EnableThinking
		enableThinking = &value
	}

	return &QwenChatModel{
		rest:                rest,
		model:               config.Model,
		temperature:         temperature,
		maxCompletionTokens: config.MaxCompletionTokens,
		enableThinking:      enableThinking,
	}, nil
}

type qwenChatRequest struct {
	Model               string        `json:"model"`
	Messages            []qwenMessage `json:"messages"`
	Temperature         *float64      `json:"temperature,omitempty"`
	MaxCompletionTokens int           `json:"max_completion_tokens,omitempty"`
	EnableThinking      *bool         `json:"enable_thinking,omitempty"`
}

type qwenMessage struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

type qwenChatResponse struct {
	ID      string `json:"id"`
	Object  string `json:"object"`
	Model   string `json:"model"`
	Choices []struct {
		Index        int    `json:"index"`
		FinishReason string `json:"finish_reason"`
		Message      struct {
			Role             string `json:"role"`
			Content          string `json:"content"`
			ReasoningContent string `json:"reasoning_content"`
		} `json:"message"`
	} `json:"choices"`
	Usage struct {
		PromptTokens     int `json:"prompt_tokens"`
		CompletionTokens int `json:"completion_tokens"`
		TotalTokens      int `json:"total_tokens"`
		PromptDetails    struct {
			CachedTokens int `json:"cached_tokens"`
		} `json:"prompt_tokens_details"`
	} `json:"usage"`
}

func (m *QwenChatModel) Complete(ctx context.Context, systemPrompt, userPrompt string) (ChatResult, error) {
	if strings.TrimSpace(systemPrompt) == "" {
		return ChatResult{}, fmt.Errorf("qwen chat: system prompt is empty")
	}
	if strings.TrimSpace(userPrompt) == "" {
		return ChatResult{}, fmt.Errorf("qwen chat: user prompt is empty")
	}

	request := qwenChatRequest{
		Model: m.model,
		Messages: []qwenMessage{
			{Role: "system", Content: systemPrompt},
			{Role: "user", Content: userPrompt},
		},
		Temperature:         m.temperature,
		MaxCompletionTokens: m.maxCompletionTokens,
		EnableThinking:      m.enableThinking,
	}

	var response qwenChatResponse
	if err := m.rest.doJSON(ctx, http.MethodPost, qwenChatCompletionsPath, nil, request, &response); err != nil {
		return ChatResult{}, err
	}
	if response.Object != "chat.completion" {
		return ChatResult{}, fmt.Errorf("qwen chat: unexpected object %q", response.Object)
	}
	if response.ID == "" {
		return ChatResult{}, fmt.Errorf("qwen chat: response ID is empty")
	}
	if response.Model == "" {
		return ChatResult{}, fmt.Errorf("qwen chat: response model is empty")
	}
	if len(response.Choices) == 0 {
		return ChatResult{}, fmt.Errorf("qwen chat: response has no choices")
	}

	choice := response.Choices[0]
	if choice.Message.Role != "assistant" {
		return ChatResult{}, fmt.Errorf("qwen chat: unexpected response role %q", choice.Message.Role)
	}
	if choice.FinishReason == "" {
		return ChatResult{}, fmt.Errorf("qwen chat: finish reason is empty")
	}

	return ChatResult{
		ID:               response.ID,
		Model:            response.Model,
		Content:          choice.Message.Content,
		ReasoningContent: choice.Message.ReasoningContent,
		FinishReason:     choice.FinishReason,
		Usage: TokenUsage{
			PromptTokens:     response.Usage.PromptTokens,
			CompletionTokens: response.Usage.CompletionTokens,
			TotalTokens:      response.Usage.TotalTokens,
			CachedTokens:     response.Usage.PromptDetails.CachedTokens,
		},
	}, nil
}
