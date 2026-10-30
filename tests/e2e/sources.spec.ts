import type { Locator, Page } from '@playwright/test';
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

/** Waits for the debounced server search, so row menus do not close on reload. */
async function search(page: Page, placeholder: string, term: string) {
    await page
        .getByRole('group', { name: /^Поиск/ })
        .getByRole('button', { name: placeholder })
        .click();
    await page.keyboard.type(term);
    await page.keyboard.press('Enter');
    await page.waitForURL((url) => url.searchParams.get('search') === term);
    await page.waitForLoadState('networkidle');
}

async function choose(page: Page, trigger: Locator, option: string) {
    await trigger.click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

async function createShowroom(page: Page, name: string) {
    await page.goto('/showrooms');
    await page.getByRole('button', { name: 'Добавить салон' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('#showroom-name').fill(name);
    await dialog.getByRole('button', { name: 'Создать' }).click();
    await expect(page.getByText(`Салон «${name}» создан.`)).toBeVisible();
    await expect(dialog).toBeHidden();
}

test('admin configures sources and connections; a showroom employee sees only own sources', async ({
    page,
}, testInfo) => {
    test.skip(
        !adminEmail || !adminPassword,
        'E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD are required',
    );
    test.setTimeout(360_000);

    const suffix = `${testInfo.project.name}-${Date.now().toString(36)}`;
    const showroomA = `Салон А ${suffix}`;
    const showroomB = `Салон Б ${suffix}`;
    const connectionName = `Mango ${suffix}`;
    const siteName = `Сайт ${suffix}`;
    const botName = `Бот ${suffix}`;
    const foreignName = `Чужой ${suffix}`;
    const secretKey = `key-${Date.now()}-e2e`;
    const secretSalt = `salt-${Date.now()}-e2e`;
    const employee = {
        name: `Руководитель ${suffix}`,
        email: `director.${suffix}@example.com`,
        password: `Dr-${Date.now()}-Secret`,
    };

    await login(page, adminEmail, adminPassword);
    await createShowroom(page, showroomA);
    await createShowroom(page, showroomB);

    // Mango VPBX account: settings only, secrets shown as presence flags
    await page.goto('/connections');
    await expect(
        page.getByText('Сохраняются только настройки', { exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Добавить подключение' }).click();
    const connectionDialog = page.getByRole('dialog');
    await connectionDialog.locator('#connection-name').fill(connectionName);
    await connectionDialog.getByRole('checkbox', { name: showroomA }).click();
    await connectionDialog.locator('#connection-api_key').fill(secretKey);
    await connectionDialog.locator('#connection-api_salt').fill(secretSalt);
    await connectionDialog.getByRole('button', { name: 'Создать' }).click();
    await expect(
        page.getByText(
            `Подключение «${connectionName}» сохранено. Состояние: не проверено.`,
        ),
    ).toBeVisible();
    await search(page, 'название…', suffix);
    await expect(page.getByText('API key: задан')).toBeVisible();
    await expect(page.getByText('API salt: задан')).toBeVisible();
    expect(await page.content()).not.toContain(secretKey);

    await page
        .getByRole('button', { name: `Действия: ${connectionName}` })
        .click();
    await page.getByRole('menuitem', { name: 'Изменить' }).click();
    await expect(connectionDialog.locator('#connection-api_key')).toHaveValue(
        '',
    );
    await expect(
        connectionDialog.locator('#connection-api_key'),
    ).toHaveAttribute('placeholder', 'Оставьте пустым, чтобы не менять');
    await connectionDialog.getByRole('button', { name: 'Отмена' }).click();
    await expect(connectionDialog).toBeHidden();

    // Site in showroom A with channels, site intake and Mango binding
    await page.goto('/sources');
    await page.getByRole('button', { name: 'Добавить источник' }).click();
    const sourceDialog = page.getByRole('dialog');
    await sourceDialog.locator('#source-name').fill(siteName);
    await choose(page, sourceDialog.locator('#source-showroom'), showroomA);
    await choose(page, sourceDialog.locator('#source-kind'), 'Сайт');
    await choose(
        page,
        sourceDialog.locator('#source-classification'),
        'Реклама',
    );
    for (const channel of ['Обратный звонок', 'Прямой звонок', 'Чат']) {
        await expect(
            sourceDialog.getByRole('checkbox', { name: channel }),
        ).toBeChecked();
    }
    await expect(
        sourceDialog.getByRole('checkbox', { name: 'Форма/заявка' }),
    ).toHaveCount(0);
    await sourceDialog.getByRole('checkbox', { name: 'Чат' }).click();
    await sourceDialog
        .getByRole('checkbox', { name: /Приём заявок с сайта/ })
        .click();
    await choose(page, sourceDialog.locator('#source-mango'), connectionName);
    await sourceDialog.getByRole('button', { name: 'Добавить линию' }).click();
    await sourceDialog.locator('#source-line-0').fill('+7 999 000-11-22');
    await sourceDialog.getByRole('button', { name: 'Добавить линию' }).click();
    await choose(
        page,
        sourceDialog.getByRole('combobox', { name: 'Тип линии' }).nth(1),
        'SIP',
    );
    await sourceDialog
        .locator('#source-line-1')
        .fill(`sales.${Date.now()}@vpbx.mangosip.ru`);
    await sourceDialog.getByRole('button', { name: 'Создать' }).click();
    await expect(
        page.getByText(`Источник «${siteName}» создан.`),
    ).toBeVisible();
    await expect(sourceDialog).toBeHidden();

    await search(page, 'название, код или адрес…', suffix);
    await expect(page.getByText('API сайта · не проверено')).toBeVisible();
    await expect(page.getByText('Mango · не проверено')).toBeVisible();
    await page.screenshot({
        path: `storage/e2e/${testInfo.project.name}-sources.png`,
        fullPage: true,
    });

    // Edit keeps bindings
    await page.getByRole('button', { name: `Действия: ${siteName}` }).click();
    await page.getByRole('menuitem', { name: 'Изменить' }).click();
    await expect(sourceDialog.locator('#source-line-0')).toHaveValue(
        '+7 999 000-11-22',
    );
    await expect(
        sourceDialog.getByRole('checkbox', { name: 'Чат' }),
    ).not.toBeChecked();
    await sourceDialog.locator('#source-name').fill(`${siteName} 2`);
    await sourceDialog.getByRole('button', { name: 'Сохранить' }).click();
    await expect(
        page.getByText(`Источник «${siteName} 2» сохранён.`),
    ).toBeVisible();
    await expect(sourceDialog).toBeHidden();
    await page.waitForLoadState('networkidle');

    // Disable and enable
    await page.getByRole('button', { name: `Действия: ${siteName} 2` }).click();
    await page.getByRole('menuitem', { name: 'Отключить' }).click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Отключить' })
        .click();
    await expect(
        page.getByText(`Источник «${siteName} 2» отключён.`),
    ).toBeVisible();
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: `Действия: ${siteName} 2` }).click();
    await page.getByRole('menuitem', { name: 'Включить' }).click();
    await expect(
        page.getByText(`Источник «${siteName} 2» включён.`),
    ).toBeVisible();

    // Bot without URL and classification
    await page.getByRole('button', { name: 'Добавить источник' }).click();
    await sourceDialog.locator('#source-name').fill(botName);
    await choose(page, sourceDialog.locator('#source-showroom'), showroomA);
    await choose(page, sourceDialog.locator('#source-kind'), 'Бот');
    await expect(sourceDialog.locator('#source-classification')).toHaveText(
        'Без классификации',
    );
    await sourceDialog.getByRole('button', { name: 'Создать' }).click();
    await expect(page.getByText(`Источник «${botName}» создан.`)).toBeVisible();
    await expect(sourceDialog).toBeHidden();

    // Source of another showroom
    await page.getByRole('button', { name: 'Добавить источник' }).click();
    await sourceDialog.locator('#source-name').fill(foreignName);
    await choose(page, sourceDialog.locator('#source-showroom'), showroomB);
    await choose(page, sourceDialog.locator('#source-kind'), 'Партнёр');
    await sourceDialog.getByRole('button', { name: 'Создать' }).click();
    await expect(
        page.getByText(`Источник «${foreignName}» создан.`),
    ).toBeVisible();

    // Employee of showroom A only
    await page.goto('/users/create');
    await page.locator('#user-name').fill(employee.name);
    await page.locator('#user-email').fill(employee.email);
    await page.locator('#user-password').fill(employee.password);
    await page.locator('#user-password-confirmation').fill(employee.password);
    await page
        .getByRole('checkbox', { name: 'Руководитель', exact: true })
        .click();
    await page.getByRole('checkbox', { name: showroomA }).click();
    await page.getByRole('button', { name: 'Создать сотрудника' }).click();
    await page.waitForURL('**/users/*/edit');

    await page.context().clearCookies();
    await login(page, employee.email, employee.password);
    await page.goto('/sources');
    await search(page, 'название, код или адрес…', suffix);
    await expect(
        page.getByText(`${siteName} 2`, { exact: true }),
    ).toBeVisible();
    await expect(page.getByText(botName, { exact: true })).toBeVisible();
    await expect(page.getByText(foreignName, { exact: true })).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Добавить источник' }),
    ).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Подключения' })).toHaveCount(
        0,
    );
    await page.screenshot({
        path: `storage/e2e/${testInfo.project.name}-sources-employee.png`,
        fullPage: true,
    });

    await page.goto('/connections');
    await expect(
        page.getByRole('heading', { name: 'Нет доступа' }),
    ).toBeVisible();

    // Role with connections.manage and reference access, but without full administrator access
    await page.context().clearCookies();
    await login(page, adminEmail, adminPassword);

    const usage = async (kind: string) => {
        const row = page
            .getByRole('row')
            .filter({ has: page.getByText(kind, { exact: true }) });

        return Number(await row.getByRole('cell').nth(3).innerText());
    };

    await page.goto('/source-references/kinds');
    expect(await usage('Партнёр')).toBeGreaterThanOrEqual(1);

    const roleLabel = `Интегратор ${suffix}`;
    await page.goto('/roles/create');
    await page.locator('#role-label').fill(roleLabel);
    await page.locator('#role-name').fill(`e2e_${Date.now().toString(36)}`);
    for (const permission of [
        'workspace.view',
        'sources.view',
        'source_references.view',
        'connections.manage',
    ]) {
        await page.locator(`[id="permission-${permission}"]`).click();
    }
    await page.getByRole('button', { name: 'Создать роль' }).click();
    await expect(page.getByText(`Роль «${roleLabel}» создана.`)).toBeVisible();

    const integrator = {
        name: `Интегратор ${suffix}`,
        email: `integrator.${suffix}@example.com`,
        password: `In-${Date.now()}-Secret`,
    };
    await page.goto('/users/create');
    await page.locator('#user-name').fill(integrator.name);
    await page.locator('#user-email').fill(integrator.email);
    await page.locator('#user-password').fill(integrator.password);
    await page.locator('#user-password-confirmation').fill(integrator.password);
    await page.getByRole('checkbox', { name: roleLabel, exact: true }).click();
    await page.getByRole('checkbox', { name: showroomA }).click();
    await page.getByRole('button', { name: 'Создать сотрудника' }).click();
    await page.waitForURL('**/users/*/edit');

    await page.context().clearCookies();
    await login(page, integrator.email, integrator.password);
    await expect(
        page.getByRole('link', { name: 'Справочники источников' }),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: 'Подключения' })).toHaveCount(
        0,
    );

    await page.goto('/source-references/kinds');
    expect(await usage('Сайт')).toBe(1);
    expect(await usage('Бот')).toBe(1);
    expect(await usage('Партнёр')).toBe(0);
    await page.screenshot({
        path: `storage/e2e/${testInfo.project.name}-references-employee.png`,
        fullPage: true,
    });

    await page.goto('/connections');
    await expect(
        page.getByRole('heading', { name: 'Нет доступа' }),
    ).toBeVisible();
});
