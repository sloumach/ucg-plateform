import type { ReactNode } from 'react'
import { t } from '../../i18n/fr'
import { AsyncState } from '../feedback/AsyncState'

export type DataTableColumn<Row> = {
  header: string
  key: string
  render: (row: Row) => ReactNode
}

type DataTableProps<Row> = {
  caption: string
  columns: DataTableColumn<Row>[]
  emptyDescription?: string
  emptyTitle: string
  rowKey: (row: Row) => string | number
  rows: Row[]
}

export function DataTable<Row>({
  caption,
  columns,
  emptyDescription,
  emptyTitle,
  rowKey,
  rows,
}: DataTableProps<Row>) {
  return (
    <div role="region" aria-label={t('table.scrollRegion', { caption })} tabIndex={0} className="min-w-0 overflow-x-auto rounded-xl border border-outline bg-field">
      <table className={`w-full border-collapse text-left text-sm ${rows.length > 0 && columns.length > 2 ? 'min-w-[28rem]' : ''}`}>
        <caption className="sr-only">{caption}</caption>
        <thead className="bg-raised text-slate-100">
          <tr>
            {columns.map((column) => (
              <th key={column.key} scope="col" className="px-3 py-3 font-semibold sm:px-4">
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-outline">
          {rows.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="p-4">
                <AsyncState
                  variant="empty"
                  title={emptyTitle}
                  description={emptyDescription}
                />
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={rowKey(row)} className="text-slate-200 even:bg-surface focus-within:bg-raised">
                {columns.map((column) => (
                  <td key={column.key} className="px-3 py-3 align-middle sm:px-4">
                    {column.render(row)}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  )
}
