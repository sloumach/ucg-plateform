import { useEffect, useRef, type ReactNode } from 'react'

export function WorkspaceEditor({ title, children }: { title: string; children: ReactNode }) {
  const heading = useRef<HTMLHeadingElement>(null)

  useEffect(() => {
    heading.current?.focus()
  }, [])

  return <div className="workspace-editor grid min-w-0 gap-3">
    <h3 ref={heading} tabIndex={-1} className="scroll-mt-36 text-lg font-semibold wrap-anywhere focus:outline-none">{title}</h3>
    {children}
  </div>
}
