/**
 * Zywrap API Client for Node.js
 */
export default class Zywrap {
    /**
     * Initialize the Zywrap client.
     * @param {string} apiKey - Your Zywrap API Key.
     * @param {object} [options] - Optional configuration.
     * @param {string} [options.baseUrl] - Custom API endpoint URL.
     */
    constructor(apiKey, options = {}) {
        if (!apiKey || typeof apiKey !== 'string') {
            throw new Error("Zywrap Initialization Error: A valid API Key is required.");
        }
        this.apiKey = apiKey.trim();
        this.baseUrl = options.baseUrl || 'https://api.zywrap.com/v1/proxy';
    }

    /**
     * Execute a Zywrap AI Wrapper.
     * @param {object} params - The execution parameters.
     * @param {string} params.model - The AI model to use (e.g., 'openai-gpt-5.4').
     * @param {string[]} params.wrapperCodes - Array of wrapper codes to execute.
     * @param {object} [params.variables={}] - Dynamic variables injected into the prompt.
     * @param {string} [params.prompt=""] - Additional free-form user instructions.
     * @param {string} [params.language=""] - Target language code for the output.
     * @returns {Promise<{data: any, status: number}>} The API response.
     */
    async execute({ model, wrapperCodes, variables = {}, prompt = '', language = '' }) {
        if (!model || !wrapperCodes || !Array.isArray(wrapperCodes)) {
            throw new Error("Zywrap Execution Error: 'model' and 'wrapperCodes' (array) are required.");
        }

        const payload = {
            model,
            wrapperCodes,
            variables,
            prompt,
            source: 'node_sdk'
        };

        if (language) payload.language = language;

        try {
            const response = await fetch(this.baseUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.apiKey}`,
                    'User-Agent': 'Zywrap/NodeSDK/1.0.0'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                let errorData;
                try { errorData = await response.json(); } catch (e) { errorData = { error: response.statusText }; }
                throw new Error(errorData.error || `Zywrap API Error (${response.status})`);
            }

            const data = await response.json();
            return { data, status: response.status };

        } catch (error) {
            throw error;
        }
    }
}