import { computed } from 'vue';

// Decimal columns come back from the API as numeric strings, so every display
// value should go through this before .toFixed() to avoid
// "toFixed is not a function".
export function fmtMoney(value) {
    return Number(value || 0).toFixed(2);
}

// Shared live cost/profit estimate for anything that turns raw materials into
// an output product (recipe builder, production entry) — same basis the
// production module itself costs against once a batch completes: material
// cost from each line's current purchase_rate, output value from the output
// product's current sale_rate.
//
// materialLines: computed/ref of [{ quantity, rate }]
// outputQuantity: computed/ref of a number
// outputProduct: computed/ref of a product object (or null) with a sale_rate
export function useProductionCostEstimate(materialLines, outputQuantity, outputProduct) {
    const materialCost = computed(() =>
        materialLines.value.reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.rate) || 0), 0)
    );

    const unitCost = computed(() => {
        const qty = Number(outputQuantity.value) || 0;
        return qty > 0 ? materialCost.value / qty : 0;
    });

    const saleRate = computed(() => Number(outputProduct.value?.sale_rate || 0));

    const outputValue = computed(() => (Number(outputQuantity.value) || 0) * saleRate.value);

    const profit = computed(() => outputValue.value - materialCost.value);

    const marginPercent = computed(() => (outputValue.value > 0 ? (profit.value / outputValue.value) * 100 : null));

    return { materialCost, unitCost, saleRate, outputValue, profit, marginPercent };
}
