import { useState } from 'react';
import Box from '@mui/material/Box';
import TextField from '@mui/material/TextField';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import MenuItem from '@mui/material/MenuItem';
import Alert from '@mui/material/Alert';
import Typography from '@mui/material/Typography';
import axios from 'axios';

const PAGES = ['Home', 'Page_01', 'Page_02', 'Page_03'];

export default function CmsAddContentForm({ page, onAdded }) {
  const [title, setTitle] = useState('');
  const [details, setDetails] = useState('');
  const [targetPage, setTargetPage] = useState(page);
  const [image, setImage] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState(null);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setSubmitting(true);
    setMessage(null);

    const formData = new FormData();
    formData.append('page', targetPage);
    formData.append('title', title);
    formData.append('details', details);
    if (image) formData.append('image', image);

    try {
      // Plain axios (not the shared JSON apiClient) so the browser sets the multipart boundary.
      await axios.post('/api/v1/content', formData, { withCredentials: true });
      setTitle('');
      setDetails('');
      setImage(null);
      setMessage({ severity: 'success', text: 'Content added.' });
      onAdded?.();
    } catch (err) {
      setMessage({ severity: 'error', text: err.response?.data?.error || 'Could not add content.' });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Box component="form" onSubmit={handleSubmit} sx={{ maxWidth: 480, width: '100%', mb: 3 }}>
      <Typography variant="h6" component="h3" gutterBottom>
        Add Content
      </Typography>
      {message && <Alert severity={message.severity} sx={{ mb: 2 }}>{message.text}</Alert>}
      <Stack spacing={2}>
        <TextField select label="Page" value={targetPage} onChange={(e) => setTargetPage(e.target.value)}>
          {PAGES.map((p) => (
            <MenuItem key={p} value={p}>{p}</MenuItem>
          ))}
        </TextField>
        <TextField label="Title" value={title} onChange={(e) => setTitle(e.target.value)} required />
        <TextField
          label="Details"
          value={details}
          onChange={(e) => setDetails(e.target.value)}
          multiline
          minRows={3}
        />
        <Button variant="outlined" component="label">
          {image ? image.name : 'Choose Image (optional)'}
          <input
            type="file"
            hidden
            accept=".jpg,.jpeg,.png,.webp,.gif"
            onChange={(e) => setImage(e.target.files?.[0] || null)}
          />
        </Button>
        <Button type="submit" variant="contained" disabled={submitting}>
          {submitting ? 'Saving…' : 'Save'}
        </Button>
      </Stack>
    </Box>
  );
}
