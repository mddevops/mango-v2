export type CatalogLink = {
    name: string;
    href: string;
};

export type CatalogMarkCard = {
    name: string;
    name_ru: string | null;
    href: string;
    logo: string | null;
    logo_dark: string | null;
    models_count: number;
};

export type CatalogModelCard = {
    name: string;
    href: string;
    image: string | null;
    year_from: number | null;
    year_to: number | null;
};

export type CatalogSeriesLink = CatalogLink & {
    image: string | null;
};

export type CatalogGeneration = {
    key: string;
    name: string;
    year_from: number | null;
    year_to: number | null;
    series: CatalogSeriesLink[];
};

export type CatalogSeriesContext = {
    mark: CatalogLink;
    model: CatalogLink;
    series: CatalogLink & {
        image: string | null;
        generation: {
            name: string;
            year_from: number | null;
            year_to: number | null;
        };
    };
    siblings: (CatalogLink & { active: boolean })[];
};

export type CatalogModificationRow = {
    href: string;
    engine: string;
    transmission: string | null;
    drive: string | null;
    consumption: string | null;
    acceleration: string | null;
};

export type CatalogEquipmentRow = CatalogLink & {
    modification: string;
    options_count: number;
};

export type CatalogSection<T> = {
    name: string;
    items: T[];
};

export type CatalogFact = {
    label: string;
    value: string;
};

export type CatalogMarksPage = {
    marks: CatalogMarkCard[];
};

export type CatalogMarkPage = {
    mark: CatalogLink & {
        name_ru: string | null;
        country: string | null;
        logo: string | null;
        logo_dark: string | null;
    };
    models: CatalogModelCard[];
};

export type CatalogModelPage = {
    mark: CatalogLink;
    model: CatalogLink & {
        year_from: number | null;
        year_to: number | null;
        class: string | null;
    };
    generations: CatalogGeneration[];
};

export type CatalogSeriesPage = CatalogSeriesContext & {
    modifications: CatalogModificationRow[];
    equipments: CatalogEquipmentRow[];
};

export type CatalogSpecificationsPage = CatalogSeriesContext & {
    modification: CatalogLink;
    modifications: CatalogLink[];
    summary: CatalogFact[];
    dimensions: CatalogFact[];
    sections: CatalogSection<{ name: string; value: string }>[];
    equipments: CatalogLink[];
};

export type CatalogEquipmentPage = CatalogSeriesContext & {
    modification: CatalogLink;
    modifications: CatalogLink[];
    equipment: { name: string };
    equipments: (CatalogLink & { details: string; active: boolean })[];
    sections: CatalogSection<string>[];
};
