import { useEffect, useState } from 'react';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableSortLabel from '@mui/material/TableSortLabel';
import Paper from '@mui/material/Paper';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import Alert from '@mui/material/Alert';
import Typography from '@mui/material/Typography';
import Stack from '@mui/material/Stack';
import apiClient from '../api/client';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import { useSearchParams } from 'react-router-dom';
import TransactionTable from '../components/TransactionTable';

const compactCellSx = {
  px: { xs: 0.5, sm: 2 },
  py: { xs: 0.75, sm: 1.5 },
  fontSize: { xs: '0.75rem', sm: '0.875rem' },
  overflowWrap: 'anywhere',
  whiteSpace: 'normal',
  '& .MuiTableSortLabel-root': { whiteSpace: 'normal', lineHeight: 1.1 },
};

export default function WhereDaMoney() {
  const [searchParams] = useSearchParams();
  const [budgets, setBudgets] = useState([]);
  const [budgetId, setBudgetId] = useState('');
  const [canWrite, setCanWrite] = useState(false);
  const [categories, setCategories] = useState([]);
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [summary, setSummary] = useState([]);
  const [transactions, setTransactions] = useState([]);
  const [selectedCategory, setSelectedCategory] = useState(null);
  const [status, setStatus] = useState('loading');
  const [categorySort, setCategorySort] = useState('actual_spent');
  const [categorySortDirection, setCategorySortDirection] = useState('desc');

  useEffect(() => {
    apiClient.get('/budget/budgets').then((response) => {
      setBudgets(response.data);
      if (response.data.length > 0) {
        const requestedBudget = response.data.find((budget) => String(budget.id) === searchParams.get('budget'));
        setBudgetId(String((requestedBudget || response.data[0]).id));
      } else {
        // Nothing further to load, so stop here instead of waiting on a budget.
        setStatus('ready');
      }
    }).catch(() => setStatus('error'));
  }, []);

  useEffect(() => {
    const selectedBudget = budgets.find((budget) => String(budget.id) === String(budgetId));
    setCanWrite(['owner', 'editor'].includes(selectedBudget?.permission));
    setSelectedCategory(String(budgetId) === searchParams.get('budget') ? searchParams.get('category') : null);
  }, [budgetId, budgets]);

  useEffect(() => {
    if (!budgetId) return;
    setStatus('loading');
    Promise.all([
      apiClient.get('/budget/transactions', { params: { budget_id: budgetId } }),
      apiClient.get('/budget/categories', { params: { budget_id: budgetId } }),
    ])
      .then(async ([transactionsResponse, categoriesResponse]) => {
        const allTransactions = transactionsResponse.data;
        setCategories(categoriesResponse.data);
        const latestMonth = allTransactions[0]?.transaction_date
          ? String(allTransactions[0].transaction_date).slice(0, 7)
          : month;
        if (latestMonth !== month) {
          setMonth(latestMonth);
        }
        const summaryResponse = await apiClient.get('/budget/summary', {
          params: { budget_id: budgetId, month: latestMonth },
        });
        setSummary(summaryResponse.data);
        setTransactions(allTransactions.filter((transaction) => String(transaction.transaction_date).startsWith(latestMonth)));
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  }, [budgetId, month]);

  const categoryTotals = summary
    .filter((row) => !row.parent_category_id && Number(row.actual_spent) > 0)
    .sort((left, right) => {
      const comparison = categorySort === 'category'
        ? String(left.category).localeCompare(String(right.category))
        : Number(left[categorySort]) - Number(right[categorySort]);
      return categorySortDirection === 'asc' ? comparison : -comparison;
    });
  const requestCategorySort = (column) => {
    if (column === categorySort) setCategorySortDirection((direction) => direction === 'asc' ? 'desc' : 'asc');
    else {
      setCategorySort(column);
      setCategorySortDirection('asc');
    }
  };
  const sortableCategoryHeader = (label, column, align = 'left') => (
    <TableCell align={align} sortDirection={categorySort === column ? categorySortDirection : false} sx={compactCellSx}>
      <TableSortLabel active={categorySort === column} aria-label={`Sort by ${label}${categorySort === column ? `. Currently ${categorySortDirection}ending` : ''}`} direction={categorySort === column ? categorySortDirection : 'asc'} onClick={() => requestCategorySort(column)}>
        {label}
      </TableSortLabel>
    </TableCell>
  );
  const selectedCategoryNames = selectedCategory
    ? [
      selectedCategory,
      ...categories.filter((category) => category.parent_category === selectedCategory).map((category) => category.name),
    ]
    : [];
  const categoryTransactions = selectedCategory
    ? transactions.filter((transaction) => selectedCategoryNames.includes(transaction.budget_category || transaction.category))
    : transactions;

  if (status === 'loading') return <CircularProgress aria-label="Loading spending analysis" />;
  if (status === 'error') return <Alert severity="error">Could not load spending analysis.</Alert>;

  if (budgets.length === 0) {
    return (
      <>
        <Typography variant="h4" component="h2" gutterBottom>Where Da Money</Typography>
        <Alert severity="info">
          No budgets yet. Create one on the Budget page, then come back to see where the money went.
        </Alert>
      </>
    );
  }

  return (
    <>
      <Typography variant="h4" component="h2" gutterBottom>Where Da Money</Typography>
      <Stack direction="row" spacing={2} flexWrap="wrap" sx={{ mb: 3 }}>
        <TextField
          select
          label="Budget"
          value={budgetId}
          onChange={(event) => setBudgetId(event.target.value)}
          SelectProps={{
            renderValue: (value) => {
              const current = budgets.find((budget) => String(budget.id) === String(value));
              return current ? `${current.name} (${current.permission})` : '';
            },
          }}
          sx={{ minWidth: 220 }}
        >
          {budgets.map((budget) => (
            <MenuItem key={budget.id} value={String(budget.id)}>
              {budget.name} ({budget.permission})
            </MenuItem>
          ))}
        </TextField>
        {selectedCategory && (
          <Button variant="outlined" onClick={() => setSelectedCategory(null)}>
            Show all categories
          </Button>
        )}
      </Stack>

      <Typography variant="h6" component="h3" gutterBottom>
        {selectedCategory ? `${selectedCategory} transactions` : 'Where Da Money went'}
      </Typography>

      {!selectedCategory && categoryTotals.length === 0 && (
        <Alert severity="info" sx={{ mb: 3 }}>No spending recorded for this month.</Alert>
      )}

      {!selectedCategory && categoryTotals.length > 0 && (
        <TableContainer component={Paper} sx={{ mb: 3, overflowX: 'hidden' }}>
          <Table stickyHeader size="small" aria-label="Spending by category" sx={{ tableLayout: 'fixed', width: '100%' }}>
            <TableHead>
              <TableRow>
                {sortableCategoryHeader('Category', 'category')}
                {sortableCategoryHeader('Actual spent', 'actual_spent', 'right')}
                {sortableCategoryHeader('Transactions', 'transaction_count', 'right')}
              </TableRow>
            </TableHead>
            <TableBody>
              {categoryTotals.map((row) => (
                <TableRow
                  key={row.category_id}
                  hover
                  onClick={() => setSelectedCategory(row.category)}
                  sx={{ cursor: 'pointer' }}
                >
                  <TableCell sx={compactCellSx}>
                    <Button variant="text" sx={{ minWidth: 0, p: 0, textAlign: 'left', justifyContent: 'flex-start', whiteSpace: 'normal', overflowWrap: 'anywhere' }}>{row.category}</Button>
                  </TableCell>
                  <TableCell align="right" sx={compactCellSx}>${Number(row.actual_spent).toFixed(2)}</TableCell>
                  <TableCell align="right" sx={compactCellSx}>
                    {transactions.filter((transaction) => transaction.budget_category === row.category).length}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      {(selectedCategory || transactions.length > 0) && (
        <TransactionTable
          transactions={categoryTransactions}
          categories={categories}
          canWrite={canWrite}
          ariaLabel="Spending transactions"
          headerLabels={{ description: 'Desc', amount: 'Amt', budget_category: 'Category' }}
          onChanged={async () => {
            const [transactionsResponse, categoriesResponse, summaryResponse] = await Promise.all([
              apiClient.get('/budget/transactions', { params: { budget_id: budgetId } }),
              apiClient.get('/budget/categories', { params: { budget_id: budgetId } }),
              apiClient.get('/budget/summary', { params: { budget_id: budgetId, month } }),
            ]);
            setTransactions(transactionsResponse.data.filter((transaction) => String(transaction.transaction_date).startsWith(month)));
            setCategories(categoriesResponse.data);
            setSummary(summaryResponse.data);
          }}
        />
      )}
    </>
  );
}
