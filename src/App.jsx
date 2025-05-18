import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import Layout from './components/Layout';
import Guides from './pages/Guides';
import Tours from './pages/Tours';
import { PageTitleProvider } from './contexts/PageTitleContext';

function App() {
  return (
    <PageTitleProvider>
      <Router>
        <Layout>
          <Routes>
            <Route path="/" element={<Tours />} />
            <Route path="/guides" element={<Guides />} />
            <Route path="/tours" element={<Navigate to="/" replace />} />
          </Routes>
        </Layout>
      </Router>
    </PageTitleProvider>
  );
}

export default App; 