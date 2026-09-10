// Menu group/item labels come from the backend (app/Helpers/Functions.php) as
// plain English strings. We translate them here by key (group.key, item.match)
// against resources/js/lang/*.json's "menu" section, falling back to the
// backend label when a key has no translation yet — so new/edited menu items
// degrade gracefully instead of breaking.
export function translateMenuGroups(groups, t, te) {
    return groups.map((group) => ({
        ...group,
        label: te(`menu.groups.${group.key}`) ? t(`menu.groups.${group.key}`) : group.label,
        items: group.items.map((item) => ({
            ...item,
            label: te(`menu.items.${item.match}`) ? t(`menu.items.${item.match}`) : item.label,
        })),
    }));
}
