import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { DataTable } from './DataTable'

describe('DataTable', () => {
  it('affiche un état vide accessible dans le tableau', () => {
    render(
      <DataTable
        caption="Liste des membres"
        columns={[
          {
            key: 'name',
            header: 'Nom',
            render: (row: { name: string }) => row.name,
          },
        ]}
        emptyTitle="Aucun membre"
        emptyDescription="Ajoutez un membre pour commencer."
        rowKey={(row) => row.name}
        rows={[]}
      />,
    )

    expect(screen.getByRole('table', { name: 'Liste des membres' })).toBeVisible()
    expect(screen.getByRole('columnheader', { name: 'Nom' })).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent('Aucun membre')
  })
})
