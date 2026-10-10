import { expect, test } from '@playwright/test'

test('le socle frontend communique avec l API Laravel', async ({ page }) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { name: 'Connexion' })).toBeVisible()
  await expect(page.getByText(/opérationnelle$/)).toBeVisible()
  await expect(page.getByLabel('Adresse e-mail')).toBeEditable()
  await expect(page.getByLabel('Mot de passe')).toBeEditable()

  await page.keyboard.press('Tab')
  await expect(page.getByLabel('Adresse e-mail')).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(page.getByLabel('Mot de passe')).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(page.getByRole('button', { name: 'Se connecter' })).toBeFocused()
})
