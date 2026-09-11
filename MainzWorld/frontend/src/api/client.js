import axios from 'axios';

// Base client for the PHP REST API described in api/openapi.yaml.
const apiClient = axios.create({
  baseURL: '/api/v1',
  headers: { 'Content-Type': 'application/json' },
  withCredentials: true, // send/receive the PHP session cookie used by CMS auth
});

export default apiClient;
