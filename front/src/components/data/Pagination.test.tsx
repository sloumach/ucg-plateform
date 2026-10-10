import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { Pagination } from './Pagination'
import { paginationItems } from './paginationItems'

describe('Pagination', () => {
  it('condense les longues listes autour de la page actuelle', () => {
    expect(paginationItems(5, 10)).toEqual([
      1,
      'ellipsis-start',
      4,
      5,
      6,
      'ellipsis-end',
      10,
    ])
  })

  it('annonce la page actuelle et permet de passer à la suivante', async () => {
    const user = userEvent.setup()
    const onPageChange = vi.fn()

    render(
      <Pagination currentPage={2} totalPages={4} onPageChange={onPageChange} />,
    )

    expect(screen.getByRole('button', { name: 'Page 2, page actuelle' })).toHaveAttribute(
      'aria-current',
      'page',
    )

    await user.click(screen.getByRole('button', { name: 'Page suivante' }))

    expect(onPageChange).toHaveBeenCalledWith(3)
  })
})
