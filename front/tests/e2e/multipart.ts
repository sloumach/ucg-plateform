import { randomUUID } from 'node:crypto'
import { createServer } from 'node:http'
import { test as base, type Route } from '@playwright/test'

type Reply = { status?: number; json: unknown }
type Outcome = { ok: true } | { ok: false; error: unknown }
type Receiver = { reply: (form: FormData) => Reply | Promise<Reply>; complete: (outcome: Outcome) => void }
type MultipartUpload = (route: Route, reply: Receiver['reply']) => Promise<void>

// Chromium's network inspector can omit file parts. Receive the real browser body instead.
export const test = base.extend<{ multipartUpload: MultipartUpload }>({
  multipartUpload: async ({ baseURL }, provide) => {
    const pending = new Map<string, Receiver>()
    const headers = { 'Access-Control-Allow-Origin': new URL(baseURL!).origin, 'Access-Control-Allow-Credentials': 'true', 'Content-Type': 'application/json' }
    const server = createServer((request, response) => {
      const receiver = pending.get(request.url ?? '')
      if (!receiver || request.method !== 'POST') { response.writeHead(404); response.end(); return }
      const receive = async () => {
        try {
          const chunks: Buffer[] = []
          let size = 0
          for await (const chunk of request) {
            const bytes = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk)
            size += bytes.length
            if (size > 1024 * 1024) throw new Error('Multipart test upload exceeds its fixture budget.')
            chunks.push(bytes)
          }
          const contentType = request.headers['content-type']
          if (!contentType?.startsWith('multipart/form-data;')) throw new Error('Expected a browser multipart upload.')
          const form = await new Response(new Uint8Array(Buffer.concat(chunks)).buffer, { headers: { 'Content-Type': contentType } }).formData()
          const result = await receiver.reply(form)
          response.writeHead(result.status ?? 200, headers)
          response.end(JSON.stringify(result.json))
          receiver.complete({ ok: true })
        } catch (error) {
          response.writeHead(500, headers); response.end(JSON.stringify({ message: 'Multipart fixture assertion failed.' }))
          receiver.complete({ ok: false, error })
        } finally { pending.delete(request.url ?? '') }
      }
      void receive()
    })
    await new Promise<void>((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve) })
    const address = server.address()
    if (!address || typeof address === 'string') throw new Error('Multipart fixture did not bind a local port.')
    try {
      await provide(async (route, reply) => {
        const key = '/' + randomUUID()
        const received = Promise.withResolvers<Outcome>()
        pending.set(key, { reply, complete: received.resolve })
        const timeout = setTimeout(() => received.resolve({ ok: false, error: new Error('Browser multipart upload was not received.') }), 10_000)
        try {
          // Keep the browser's original multipart body; do not replace it with inspector postData.
          await route.continue({ url: 'http://127.0.0.1:' + address.port + key })
          const result = await received.promise
          if (!result.ok) throw result.error
        } finally { clearTimeout(timeout); pending.delete(key) }
      })
    } finally {
      for (const receiver of pending.values()) receiver.complete({ ok: false, error: new Error('Multipart fixture closed.') })
      pending.clear(); server.closeAllConnections()
      await new Promise<void>((resolve, reject) => server.close((error) => error ? reject(error) : resolve()))
    }
  },
})
