import type { ReactNode } from 'react'
import { t } from '../../i18n/fr'

export function WorkspaceFilters({ children }: { children: ReactNode }) {
  return <details className="workspace-filters min-w-0">
    <summary>{t('workspace.filters')}</summary>
    {children}
  </details>
}
