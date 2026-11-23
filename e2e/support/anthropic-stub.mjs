/**
 * A scripted stand-in for the model provider, for the e2e run only (P-08, P-10): no key exists, so the Laravel server is pointed here with
 * ANTHROPIC_BASE_URL. It answers POST /v1/messages like the Messages API does and remembers every request body, so a spec can assert what the
 * app really sent (temperature, cache breakpoints, strict tools, and that no formula reached it without consent).
 *
 *   a question  -> a calculate_formula tool call (a made-up formula id: the engine never has to run)
 *   tool result -> a text reply that says whether the tool was refused (it holds no digit, so the number guard has nothing to reject)
 *   GET /__requests (all bodies so far), DELETE /__requests (forget them)
 */
import { createServer } from 'node:http';

const port = Number(process.argv[2] ?? 8002);
const requests = [];

const text = (value) => ({ content: [{ type: 'text', text: value }], stop_reason: 'end_turn' });
const usage = { input_tokens: 40, output_tokens: 10, cache_creation_input_tokens: 0, cache_read_input_tokens: 0 };

function answer(body) {
  const last = body.messages?.at(-1);
  const results = Array.isArray(last?.content) ? last.content.filter((block) => block.type === 'tool_result') : [];
  if (results.length > 0) {
    return text(results.some((result) => result.is_error) ? 'محتاج موافقتك الأول: اسمح بالفئة المطلوبة من لوحة المساعد.' : 'الأداة اتنفذت من غير ما أخترع أي رقم.');
  }
  if (!Array.isArray(body.tools) || !body.tools.some((tool) => tool.name === 'calculate_formula')) {
    return text('تمام.');
  }

  return { content: [{ type: 'tool_use', id: 'toolu_stub', name: 'calculate_formula', input: { formulaVersionId: '00000000-0000-4000-8000-000000000000' } }], stop_reason: 'tool_use' };
}

createServer((request, response) => {
  const send = (status, payload) => { response.writeHead(status, { 'content-type': 'application/json' }); response.end(JSON.stringify(payload)); };

  if (request.url === '/__requests') {
    if (request.method === 'DELETE') { requests.length = 0; }
    return send(200, requests);
  }
  if (request.url === '/up') {
    return send(200, { ok: true });
  }

  let raw = '';
  request.on('data', (chunk) => { raw += chunk; });
  request.on('end', () => {
    if (request.method !== 'POST' || request.url !== '/v1/messages') {
      return send(404, { error: { type: 'not_found_error' } });
    }
    const body = JSON.parse(raw);
    requests.push(body);
    send(200, { id: 'msg_stub', type: 'message', role: 'assistant', model: body.model, ...answer(body), usage });
  });
}).listen(port, '127.0.0.1');
