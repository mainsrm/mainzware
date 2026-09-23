import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableSortLabel from '@mui/material/TableSortLabel';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Typography from '@mui/material/Typography';
import apiClient from '../api/client';

export default function TransactionTable({ transactions, categories, canWrite, onChanged, ariaLabel = 'Budget transactions' }) {
  const [transactionSearch, setTransactionSearch] = useState('');
  const [editingTransaction, setEditingTransaction] = useState(null);
  const [editingField, setEditingField] = useState('category');
  const [editingCategory, setEditingCategory] = useState('');
  const [newTransactionCategory, setNewTransactionCategory] = useState('');
  const [applyFutureRule, setApplyFutureRule] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [sortColumn, setSortColumn] = useState('transaction_date');
  const [sortDirection, setSortDirection] = useState('desc');
  const searchText = transactionSearch.trim().toLowerCase();
  const categoryFor = (transaction) => categories.find(
    (category) => category.name === (transaction.budget_category || transaction.category),
  );
  const parentCategoryFor = (transaction) => categoryFor(transaction)?.parent_category || null;
  const filteredTransactions = transactions.filter((transaction) => !searchText || [
    transaction.transaction_date,
    transaction.description,
    transaction.merchant,
    transaction.budget_category || transaction.category,
    transaction.account,
    transaction.amount,
  ].some((value) => String(value || '').toLowerCase().includes(searchText))).sort((left, right) => {
    const valueFor = (transaction) => {
      if (sortColumn === 'budget_category') return parentCategoryFor(transaction) || transaction.budget_category || transaction.category || '';
      if (sortColumn === 'subcategory') return parentCategoryFor(transaction) ? transaction.budget_category || transaction.category || '' : '';
      return transaction[sortColumn] || '';
    };
    const leftValue = valueFor(left);
    const rightValue = valueFor(right);
    const comparison = sortColumn === 'amount'
      ? Number(leftValue) - Number(rightValue)
      : String(leftValue).localeCompare(String(rightValue), undefined, { numeric: true });
    return sortDirection === 'asc' ? comparison : -comparison;
  });

  const requestSort = (column) => {
    if (column === sortColumn) {
      setSortDirection((direction) => direction === 'asc' ? 'desc' : 'asc');
      return;
    }
    setSortColumn(column);
    setSortDirection('asc');
  };

  const sortableHeader = (label, column, align = 'left') => (
    <TableCell align={align} sortDirection={sortColumn === column ? sortDirection : false}>
      <TableSortLabel
        active={sortColumn === column}
        aria-label={`Sort by ${label}${sortColumn === column ? `. Currently ${sortDirection}ending` : ''}`}
        direction={sortColumn === column ? sortDirection : 'asc'}
        onClick={() => requestSort(column)}
      >
        {label}
      </TableSortLabel>
    </TableCell>
  );

  const editCategory = (transaction, field) => {
    setSaveError('');
    setEditingTransaction(transaction);
    setEditingField(field);
    setEditingCategory(field === 'subcategory'
      ? (parentCategoryFor(transaction) ? transaction.budget_category || transaction.category : '__none__')
      : parentCategoryFor(transaction) || transaction.budget_category || transaction.category || 'Misc');
    setNewTransactionCategory('');
    setApplyFutureRule(false);
  };

  const saveTransactionCategory = async () => {
    try {
      let category = editingCategory;
      if (editingField === 'subcategory' && category === '__none__') {
        category = parentCategoryFor(editingTransaction) || editingTransaction.budget_category || editingTransaction.category;
      }
      if (category === '__new__') {
        category = newTransactionCategory.trim();
        if (!category) {
          setSaveError(`A ${editingField === 'subcategory' ? 'subcategory' : 'category'} name is required.`);
          return;
        }
        const parentName = parentCategoryFor(editingTransaction)
          || editingTransaction.budget_category
          || editingTransaction.category;
        const parent = editingField === 'subcategory'
          ? categories.find((item) => item.name === parentName && !item.parent_category_id)
          : null;
        if (editingField === 'subcategory' && !parent) {
          setSaveError('Choose a budget category before adding a subcategory.');
          return;
        }
        await apiClient.post('/budget/categories', { name: category, parent_category_id: parent?.id || null });
      }
      await apiClient.post(`/budget/transactions/${editingTransaction.id}/category`, { category });
      if (applyFutureRule && editingTransaction.normalized_merchant) {
        await apiClient.post('/budget/category-rules', {
          pattern: editingTransaction.normalized_merchant,
          match_type: 'merchant',
          category,
        });
      }
      await onChanged();
      setEditingTransaction(null);
    } catch (error) {
      setSaveError(error.response?.data?.error || 'Could not save the transaction category.');
    }
  };

  const filteredTotal = filteredTransactions.reduce((sum, transaction) => sum + Number(transaction.amount || 0), 0);

  return (
    <>
      <TextField
        fullWidth
        label="Search transactions"
        placeholder="Description, merchant, category, account, amount, or date"
        value={transactionSearch}
        onChange={(event) => setTransactionSearch(event.target.value)}
        sx={{ mb: 2 }}
      />
      <Typography variant="subtitle1" sx={{ mb: 2, fontWeight: 700 }}>
        Total: ${filteredTotal.toFixed(2)} ({filteredTransactions.length} transaction{filteredTransactions.length === 1 ? '' : 's'})
      </Typography>
      <TableContainer component={Paper}>
        <Table stickyHeader aria-label={ariaLabel}>
          <TableHead sx={{
            '& .MuiTableCell-head': { backgroundColor: '#2f6f8f', color: '#ffffff', fontWeight: 700, whiteSpace: 'nowrap' },
            '& .MuiTableSortLabel-icon': { color: 'inherit', opacity: 1 },
            '& .MuiTableSortLabel-root.Mui-focusVisible': { outline: '3px solid #ffffff', outlineOffset: '2px' },
          }}>
            <TableRow>
              {sortableHeader('Date', 'transaction_date')}
              {sortableHeader('Description', 'description')}
              {sortableHeader('Amount', 'amount', 'right')}
              {sortableHeader('Budget category', 'budget_category')}
              {sortableHeader('Subcategory', 'subcategory')}
              {sortableHeader('Account', 'account')}
            </TableRow>
          </TableHead>
          <TableBody>
            {filteredTransactions.map((transaction) => (
              <TableRow key={transaction.id}>
                <TableCell>{transaction.transaction_date}</TableCell>
                <TableCell>{transaction.description}</TableCell>
                <TableCell align="right">${Number(transaction.amount).toFixed(2)}</TableCell>
                <TableCell>
                  {parentCategoryFor(transaction) || (
                    <Chip
                      label={transaction.budget_category || transaction.category || 'Uncategorized'}
                      size="small"
                      clickable={canWrite}
                      onClick={canWrite ? () => editCategory(transaction, 'category') : undefined}
                    />
                  )}
                </TableCell>
                <TableCell>
                  <Stack direction="row" spacing={1} alignItems="center">
                    <Chip
                      label={parentCategoryFor(transaction) ? transaction.budget_category || transaction.category : 'None'}
                      size="small"
                      clickable={canWrite}
                      onClick={canWrite ? () => editCategory(transaction, 'subcategory') : undefined}
                    />
                    {transaction.category_confidence !== null && Number(transaction.category_confidence) < 0.6 && (
                      <Chip label="Review" size="small" color="warning" variant="outlined" />
                    )}
                  </Stack>
                </TableCell>
                <TableCell>{transaction.account || '-'}</TableCell>
              </TableRow>
            ))}
            {filteredTransactions.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} align="center">No transactions match that search.</TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </TableContainer>

      <Dialog open={editingTransaction !== null} onClose={() => setEditingTransaction(null)}>
        <DialogTitle>Change transaction {editingField === 'subcategory' ? 'subcategory' : 'category'}</DialogTitle>
        <DialogContent>
          <TextField
            select
            fullWidth
            label={editingField === 'subcategory' ? 'Subcategory' : 'Budget category'}
            value={editingCategory}
            onChange={(event) => setEditingCategory(event.target.value)}
            sx={{ mt: 1, minWidth: 280 }}
          >
            {editingField === 'subcategory' && <MenuItem value="__none__">None</MenuItem>}
            {categories
              .filter((category) => editingField === 'subcategory' ? category.parent_category_id : !category.parent_category_id)
              .map((category) => <MenuItem key={category.id} value={category.name}>{category.name}</MenuItem>)}
            <MenuItem value="__new__">Add new {editingField === 'subcategory' ? 'subcategory' : 'category'}...</MenuItem>
          </TextField>
          {editingCategory === '__new__' && (
            <TextField
              fullWidth
              label={`New ${editingField === 'subcategory' ? 'subcategory' : 'category'} name`}
              value={newTransactionCategory}
              onChange={(event) => setNewTransactionCategory(event.target.value)}
              sx={{ mt: 2 }}
              autoFocus
            />
          )}
          <FormControlLabel
            control={<Checkbox checked={applyFutureRule} onChange={(event) => setApplyFutureRule(event.target.checked)} />}
            label="Apply to matching transactions and future imports"
          />
          {saveError && <Alert severity="error">{saveError}</Alert>}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setEditingTransaction(null)}>Cancel</Button>
          <Button variant="contained" onClick={saveTransactionCategory}>Save</Button>
        </DialogActions>
      </Dialog>
    </>
  );
}