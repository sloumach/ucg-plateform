import { existsSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
import { setTimeout as delay } from 'node:timers/promises'

// The PHP test obtains each signature through the real tenant authorization route.
const [directory, port, key] = process.argv.slice(2)
const sockets = []
const received = [[], [], []]
const subscriptions = new Set()
let rejected = false
const deadline = Date.now() + 18000
const publish = (name, value) => {
  const path = `${directory}/${name}.json`
  writeFileSync(`${path}.pending`, JSON.stringify(value), { flag: 'wx' })
  renameSync(`${path}.pending`, path)
}
const until = async (predicate) => {
  while (!predicate()) {
    if (Date.now() > deadline) throw new Error('Reverb protocol deadline exceeded')
    await delay(10)
  }
}
try {
  // Reverb and this worker are started together; retry only before a handshake.
  for (let index = 0; index < 3; index++) {
    let connected = false
    while (!connected) {
      if (Date.now() > deadline) throw new Error('Reverb connection deadline exceeded')
      connected = await new Promise((resolve) => {
        const socket = new WebSocket(`ws://127.0.0.1:${port}/app/${key}?protocol=7&client=qa002&version=1.0`)
        socket.addEventListener('error', () => resolve(false), { once: true })
        socket.addEventListener('message', ({ data }) => {
          const message = JSON.parse(data)
          const payload = typeof message.data === 'string' ? JSON.parse(message.data) : message.data
          if (message.event === 'pusher:connection_established') {
            sockets[index] = { socket, id: payload.socket_id }
            resolve(true)
          } else if (message.event === 'pusher_internal:subscription_succeeded') {
            subscriptions.add(index)
          } else if (message.event === 'pusher:error' && index === 2) {
            rejected = true
          } else if (message.event === 'pusher:error') {
            process.stderr.write(JSON.stringify({ index, error: payload }) + '\n')
          } else if (message.event === 'match.score.changed') {
            received[index].push(Object.fromEntries(Object.entries(payload).sort(([a], [b]) => a.localeCompare(b))))
          }
        })
      })
      if (!connected) await delay(20)
    }
  }
  publish('sockets', sockets.map(({ id }) => id))
  await until(() => existsSync(`${directory}/auth.json`))
  const auth = JSON.parse(readFileSync(`${directory}/auth.json`, 'utf8'))
  auth.forEach((data, index) => sockets[index].socket.send(JSON.stringify({ event: 'pusher:subscribe', data })))
  await until(() => subscriptions.has(0) && subscriptions.has(1) && rejected)
  publish('subscribed', { ready: true, rejected })
  await until(() => received[0].length > 0 && received[1].length > 0)
  // Keep all three sockets open briefly to detect delivery to the wrong subscriber.
  await delay(300)
  publish('received', received)
} catch (error) {
  process.stderr.write(`${error.stack}\n`)
  process.exitCode = 1
} finally {
  sockets.forEach(({ socket }) => socket.close())
}
