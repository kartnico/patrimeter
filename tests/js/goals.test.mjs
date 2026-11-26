// Datasets of the projection chart of a goal (assets/js/goals.js): styles that tell the lines apart without colour,
// amounts in euros, point of the current month.
import { beforeAll, describe, expect, test } from 'vitest';

let goals;

beforeAll(async () => {
    await import('../../assets/js/goals.js');
    goals = window.PatrimeterGoals;
});

const data = {
    actual: [600000, 620000, 640000, null, null],
    plan: [600000, null, null, null, 1200000],
    projection: [null, null, 640000, null, 700000],
    target: [1200000, 1200000, 1200000, 1200000, 1200000],
    names: { actual: 'Réalisé', plan: 'Trajectoire prévue', projection: 'Au rythme actuel', target: 'Cible' },
};
const colors = { primary: '#2f6f5e', muted: '#5d6862', text: '#1d2420', surface: '#ffffff', area: 'gradient' };

describe('goal chart', () => {
    test('four lines told apart by their style, amounts in euros', () => {
        const [actual, plan, projection, target] = goals.goalDatasets(data, colors);
        expect(actual.data).toEqual([6000, 6200, 6400, null, null]);
        expect(actual.borderDash).toBeUndefined();
        expect(actual.fill).toBe('start');
        expect(plan.borderDash).toEqual([6, 5]);
        expect(plan.spanGaps).toBe(true);
        expect(projection.borderDash).toEqual([2, 5]);
        expect(target.borderWidth).toBe(1);
        expect(target.data[0]).toBe(12000);
    });

    test('only the current month and the end of the projection get a point', () => {
        const [actual, , projection] = goals.goalDatasets(data, colors);
        expect(actual.pointRadius).toEqual([0, 0, 5, 0, 0]);
        expect(projection.pointRadius).toEqual([0, 0, 0, 0, 4]);
    });

    test('colours with transparency', () => {
        expect(goals.withAlpha('#2f6f5e', 0.5)).toBe('rgba(47, 111, 94, 0.5)');
        expect(goals.withAlpha('rgb(1, 2, 3)', 0.5)).toBe('rgb(1, 2, 3)');
    });
});
