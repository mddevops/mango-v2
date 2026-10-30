import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

const adminEmail = process.env.E2E_ADMIN_EMAIL ?? '';
const adminPassword = process.env.E2E_ADMIN_PASSWORD ?? '';

async function login(page: Page, email: string, password: string) {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByTestId('login-button').click();
    await page.waitForURL('**/dashboard');
}

test('admin builds structure and the new employee logs in with granted access', async ({
    page,
}, testInfo) => {
    test.skip(
        !adminEmail || !adminPassword,
        'E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD are required',
    );

    const suffix = `${testInfo.project.name}-${Date.now().toString(36)}`;
    const showroomName = `Салон ${suffix}`;
    const departmentName = `Продажи ${suffix}`;
    const employee = {
        name: `Оператор ${suffix}`,
        email: `operator.${suffix}@example.com`,
        password: `Op-${Date.now()}-Secret`,
    };

    await login(page, adminEmail, adminPassword);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        'Здравствуйте',
    );

    // Showroom
    await page.goto('/showrooms');

    // Closing with unsaved input asks for confirmation in a nested alert dialog
    await page.getByRole('button', { name: 'Добавить салон' }).click();
    await page.locator('#showroom-name').fill('Черновик');
    await page.keyboard.press('Escape');
    const discard = page.getByRole('alertdialog', {
        name: 'Закрыть без сохранения?',
    });
    await expect(discard).toBeVisible();
    await discard.getByRole('button', { name: 'Продолжить' }).click();
    await expect(discard).toBeHidden();
    await expect(page.locator('#showroom-name')).toHaveValue('Черновик');
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Отмена' })
        .click();
    await discard.getByRole('button', { name: 'Закрыть' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();

    await page.getByRole('button', { name: 'Добавить салон' }).click();
    const showroomDialog = page.getByRole('dialog');
    await expect(showroomDialog.locator('#showroom-code')).toHaveCount(0);
    await showroomDialog.locator('#showroom-name').fill(showroomName);
    await showroomDialog.locator('#showroom-address').fill('ул. Тестовая, 1');
    await showroomDialog.getByRole('button', { name: 'Создать' }).click();
    await expect(
        page.getByText(`Салон «${showroomName}» создан.`),
    ).toBeVisible();
    await expect(showroomDialog).toBeHidden();
    await expect(page.getByRole('button', { name: 'Сбросить' })).toHaveCount(0);
    await page.screenshot({
        path: `storage/e2e/${testInfo.project.name}-showrooms.png`,
    });

    // The search filter is pinned: open its value, type, apply
    await page
        .getByRole('group', { name: /^Поиск/ })
        .getByRole('button', { name: 'код, название, адрес…' })
        .click();
    await page.keyboard.type(suffix);
    await page.keyboard.press('Enter');
    await page.waitForURL((url) => url.searchParams.get('search') === suffix);
    await expect(
        page.getByRole('cell', { name: new RegExp(`^${showroomName}`) }),
    ).toBeVisible();
    await expect(page.getByText('Найдено: 1')).toBeVisible();
    await page.screenshot({
        path: `storage/e2e/${testInfo.project.name}-showrooms-filtered.png`,
    });

    // The generated code is shown read-only under «Дополнительно» when editing
    await page
        .getByRole('button', { name: `Действия: ${showroomName}` })
        .click();
    await page.getByRole('menuitem', { name: 'Изменить' }).click();
    await showroomDialog.getByRole('button', { name: 'Дополнительно' }).click();
    const showroomCode = showroomDialog.locator('#showroom-code');
    await expect(showroomCode).toHaveValue(/^salon-[a-z0-9-]+$/);
    await expect(showroomCode).toHaveAttribute('readonly', '');
    await expect(
        showroomDialog.getByText(
            'Технический код создан автоматически и используется для интеграций',
        ),
    ).toBeVisible();
    await showroomDialog.getByRole('button', { name: 'Отмена' }).click();
    await expect(showroomDialog).toBeHidden();

    // Department in that showroom
    await page.goto('/departments');
    await page.getByRole('button', { name: 'Добавить отдел' }).click();
    const departmentDialog = page.getByRole('dialog');
    await departmentDialog.locator('#department-showroom').click();
    await page.getByRole('option', { name: showroomName }).click();
    await expect(departmentDialog.locator('#department-code')).toHaveCount(0);
    await departmentDialog.locator('#department-name').fill(departmentName);
    await departmentDialog.getByRole('button', { name: 'Создать' }).click();
    await expect(
        page.getByText(`Отдел «${departmentName}» создан.`),
    ).toBeVisible();

    // Employee with role, showroom and department
    await page.goto('/users/create');
    await page.locator('#user-name').fill(employee.name);
    await page.locator('#user-email').fill(employee.email);
    await page.locator('#user-password').fill(employee.password);
    await page.locator('#user-password-confirmation').fill(employee.password);
    await page.getByRole('checkbox', { name: 'Оператор', exact: true }).click();
    await page.getByRole('checkbox', { name: showroomName }).click();
    await page
        .getByRole('checkbox', { name: new RegExp(`^${departmentName}`) })
        .click();
    await page.getByRole('button', { name: 'Создать сотрудника' }).click();
    await page.waitForURL('**/users/*/edit');
    await expect(
        page.getByText(`Сотрудник «${employee.name}» создан.`),
    ).toBeVisible();
    await expect(
        page.getByRole('checkbox', { name: 'Оператор', exact: true }),
    ).toBeChecked();
    await page.screenshot({
        path: `storage/e2e/${testInfo.project.name}-user-form.png`,
        fullPage: true,
    });

    // Log in as the new employee
    await page.context().clearCookies();
    await login(page, employee.email, employee.password);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        `Здравствуйте, ${employee.name}`,
    );
    await expect(page.getByText('Оператор', { exact: true })).toBeVisible();
    await expect(page.getByText(showroomName, { exact: true })).toBeVisible();
    await expect(page.getByText(departmentName, { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Сотрудники' })).toHaveCount(0);
    await page.screenshot({
        path: `storage/e2e/${testInfo.project.name}-employee-dashboard.png`,
        fullPage: true,
    });

    // No access to management pages, even by direct URL
    await page.goto('/users');
    await expect(
        page.getByRole('heading', { name: 'Нет доступа' }),
    ).toBeVisible();
});
