const fs = require('fs');
const FormData = require('form-data');
const axios = require('axios');

(async () => {
  try {
    const formData = new FormData();
    formData.append('title', 'Test slide');
    formData.append('cta', 'Buy Now');
    formData.append('dark', 1);

    // Get an auth token for an admin
    const loginRes = await axios.post('http://localhost:8000/api/auth/login', {
      email: 'admin@mannabridal.com',
      password: 'password'
    }, { headers: { 'Accept': 'application/json' } });
    
    const token = loginRes.data.token;
    
    const res = await axios.post('http://localhost:8000/api/admin/hero-slides', formData, {
      headers: {
        ...formData.getHeaders(),
        Authorization: `Bearer ${token}`,
        Accept: 'application/json'
      }
    });
    console.log("Success:", res.data);
  } catch (err) {
    if (err.response) {
      console.error("Error from API:", err.response.data);
    } else {
      console.error(err.message);
    }
  }
})();
