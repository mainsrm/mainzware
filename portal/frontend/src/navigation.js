export const NAV_ITEMS = [
  { to: '/portal', label: 'Portal Home', description: 'Return to your private MainzWare portal dashboard.' },
  { to: '/budget', label: 'Budget App', description: 'Manage budgets, spending, and debt payoff tools.' },
  { to: '/properties', label: 'Property Sales', description: 'Browse current tax-sale properties and saved lists.' },
  { to: '/technical-analysis', label: 'Stock Indicators', description: 'Review stock and crypto market indicators.' },
];

export function navigationFor(user) {
  if (!user) return [];
  return user.role === 'admin'
    ? [...NAV_ITEMS, { to: '/admin/users', label: 'Users', description: 'Manage user accounts and access.' }]
    : NAV_ITEMS;
}
