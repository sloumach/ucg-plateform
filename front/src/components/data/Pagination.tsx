import { t } from '../../i18n/fr'
import { paginationItems } from './paginationItems'

type PaginationProps = {
  currentPage: number
  disabled?: boolean
  onPageChange: (page: number) => void
  totalPages: number
}

export function Pagination({
  currentPage,
  disabled = false,
  onPageChange,
  totalPages,
}: PaginationProps) {
  if (totalPages <= 1) {
    return null
  }

  const safeCurrentPage = Math.min(Math.max(currentPage, 1), totalPages)
  const items = paginationItems(safeCurrentPage, totalPages)

  return (
    <nav aria-label={t('pagination.label')}>
      <ul className="flex flex-wrap items-center justify-center gap-1.5">
        <li>
          <PaginationButton
            label={t('pagination.previous')}
            disabled={disabled || safeCurrentPage === 1}
            onClick={() => onPageChange(safeCurrentPage - 1)}
          >
            ‹
          </PaginationButton>
        </li>
        {items.map((item) =>
          typeof item === 'number' ? (
            <li key={item}>
              <PaginationButton
                label={t(
                  item === safeCurrentPage
                    ? 'pagination.currentPage'
                    : 'pagination.page',
                  { page: item },
                )}
                current={item === safeCurrentPage}
                disabled={disabled}
                onClick={() => onPageChange(item)}
              >
                {item}
              </PaginationButton>
            </li>
          ) : (
            <li key={item} aria-hidden="true" className="px-1 text-slate-500">
              …
            </li>
          ),
        )}
        <li>
          <PaginationButton
            label={t('pagination.next')}
            disabled={disabled || safeCurrentPage === totalPages}
            onClick={() => onPageChange(safeCurrentPage + 1)}
          >
            ›
          </PaginationButton>
        </li>
      </ul>
    </nav>
  )
}

function PaginationButton({
  children,
  current = false,
  disabled,
  label,
  onClick,
}: {
  children: number | string
  current?: boolean
  disabled: boolean
  label: string
  onClick: () => void
}) {
  return (
    <button
      type="button"
      aria-current={current ? 'page' : undefined}
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
      className={`grid size-11 place-items-center rounded-lg border text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-45 ${
        current
          ? 'border-accent bg-accent text-slate-950'
          : 'border-control bg-field text-slate-200 hover:bg-raised'
      }`}
    >
      {children}
    </button>
  )
}
