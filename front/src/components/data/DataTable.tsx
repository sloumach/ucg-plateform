import type { ReactNode } from 'react'
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
    <div className="overflow-x-auto rounded-2xl border border-white/10">
      <table className="min-w-full border-collapse text-left text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead className="bg-white/[0.06] text-slate-200">
          <tr>
            {columns.map((column) => (
              <th key={column.key} scope="col" className="px-4 py-3 font-semibold">
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-white/10">
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
              <tr key={rowKey(row)} className="bg-white/[0.02] text-slate-300">
                {columns.map((column) => (
                  <td key={column.key} className="px-4 py-3 align-top">
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
