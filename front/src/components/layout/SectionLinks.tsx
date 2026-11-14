import type { MouseEvent } from 'react'

type SectionLink = { id: string; label: string; href: string }

export function SectionLinks({ label, links, current, onNavigate }: {
  label: string
  links: SectionLink[]
  current: string
  onNavigate: (id: string) => void
}) {
  function follow(event: MouseEvent<HTMLAnchorElement>, id: string) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    if (id !== current) onNavigate(id)
  }

  return (
    <nav aria-label={label} className="ui-panel p-2">
      <ul className="flex flex-wrap gap-1">
        {links.map(({ id, label: text, href }) => (
          <li key={id} className="min-w-0">
            <a
              href={href}
              aria-current={id === current ? 'page' : undefined}
              onClick={(event) => follow(event, id)}
              className="workspace-nav-link"
            >
              {text}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  )
}
