export const NAV_ITEMS = [
  { to: '/', label: 'Home', description: 'Return to your Mainz World dashboard.' },
  { to: '/what-da-money', label: 'What Da Money', description: 'Manage budgets, categories, and transactions.' },
  { to: '/where-da-money', label: 'Where Da Money', description: 'Review spending by category and transaction.' },
  { to: '/properties', label: 'Property Sales', description: 'Browse current tax-sale properties and saved lists.' },
];

export function navigationFor(user) {
  if (!user) return [];
  return user.role === 'admin'
    ? [...NAV_ITEMS, { to: '/admin/users', label: 'Users', description: 'Manage user accounts and access.' }]
    : NAV_ITEMS;
}