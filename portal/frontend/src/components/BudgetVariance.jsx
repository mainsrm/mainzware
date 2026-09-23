import { useEffect, useState } from 'react';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableSortLabel from '@mui/material/TableSortLabel';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import Alert from '@mui/material/Alert';
import CircularProgress from '@mui/material/CircularProgress';
import TextField from '@mui/material/TextField';
import Link from '@mui/material/Link';
import { Link as RouterLink } from 'react-router-dom';
import apiClient from '../api/client';

export default function BudgetVariance({ budgetId, month, canWrite }) {
  const [rows, setRows] = useState([]);
  const [status, setStatus] = useState('loading');
  const [sortColumn, setSortColumn] = useState('category');
  const [sortDirection, setSortDirection] = useState('asc');

  const requestSort = (column) => {
    if (column === sortColumn) setSortDirection((direction) => direction === 'asc' ? 'desc' : 'asc');
    else {
      setSortColumn(column);
      setSortDirection('asc');
    }
  };

  const sortedRows = rows.filter((row) => !row.parent_category_id).sort((left, right) => {
    const comparison = sortColumn === 'category'
      ? String(left.category).localeCompare(String(right.category))
      : Number(left[sortColumn]) - Number(right[sortColumn]);
    return sortDirection === 'asc' ? comparison : -comparison;
  });

  const sortableHeader = (label, column, align = 'left') => (
    <TableCell align={align} sortDirection={sortColumn === column ? sortDirection : false}>
      <TableSortLabel active={sortColumn === column} aria-label={`Sort by ${label}${sortColumn === column ? `. Currently ${sortDirection}ending` : ''}`} direction={sortColumn === column ? sortDirection : 'asc'} onClick={() => requestSort(column)}>
        {label}
      </TableSortLabel>
    </TableCell>
  );

  const saveBudgetedAmount = async (categoryId, budgetedAmount) => {
    const amount = Number(budgetedAmount);
    if (!Number.isFinite(amount) || amount < 0) return;

    await apiClient.post(`/budget/categories/${categoryId}`, { budgeted_amount: amount });
    setRows((currentRows) => currentRows.map((row) => (
      row.category_id === categoryId
        ? { ...row, budgeted_amount: amount, variance: amount - Number(row.actual_spent) }
        : row
    )));
  };

  useEffect(() => {
    apiClient
      .get('/budget/summary', { params: { budget_id: budgetId, month } })
      .then((response) => {
        setRows(response.data);
        setStatus('ready');
      })
      .catch(() => setStatus('error'));
  }, [budgetId, month]);

  if (status === 'loading') return <CircularProgress size={24} aria-label="Loading budget variance" />;
  if (status === 'error') return <Alert severity="error">Could not load budget variance.</Alert>;

  return (
    <TableContainer component={Paper} sx={{ mb: 3 }}>
      <Table stickyHeader aria-label="Budget vs actual for the current month">
        <TableHead>
          <TableRow>
            {sortableHeader('Category', 'category')}
            {sortableHeader('Budgeted amount', 'budgeted_amount', 'right')}
            {sortableHeader('Actual (this month)', 'actual_spent', 'right')}
            {sortableHeader('Variance', 'variance', 'right')}
          </TableRow>
        </TableHead>
        <TableBody>
          {sortedRows.map((row) => {
            const variance = Number(row.variance);
            return (
              <TableRow key={row.category}>
                <TableCell>
                  <Link
                    component={RouterLink}
                    to={`/where-da-money?budget=${budgetId}&category=${encodeURIComponent(row.category)}`}
                    underline="always"
                  >
                    {row.category}
                  </Link>
                </TableCell>
                <TableCell align="right">
                  <TextField
                    defaultValue={Number(row.budgeted_amount).toFixed(2)}
                    disabled={!canWrite}
                    inputProps={{ min: 0, step: '0.01', 'aria-label': `${row.category} budgeted amount` }}
                    size="small"
                    type="number"
                    onBlur={(event) => saveBudgetedAmount(row.category_id, event.target.value)}
                    sx={{ width: 120 }}
                  />
                </TableCell>
                <TableCell align="right">{Number(row.actual_spent).toFixed(2)}</TableCell>
                <TableCell align="right" sx={{ color: variance < 0 ? 'error.main' : 'success.main' }}>
                  {variance.toFixed(2)}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </TableContainer>
  );
}
