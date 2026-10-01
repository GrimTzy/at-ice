import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import logo from '../../assets/logo.png';

const STATUS_STYLES = {
  approved: { bg: 'bg-cyan-50', text: 'text-cyan-700', dot: 'bg-cyan-500' },
  released: { bg: 'bg-emerald-50', text: 'text-emerald-700', dot: 'bg-emerald-500' },
};

const ChevronLeftIcon = () => <svg width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>;
const ClockIcon = () => <svg width="12" height="12" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>;

function StatusBadge({ status }) {
  const style = STATUS_STYLES[status] || STATUS_STYLES.approved;
  return (
    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${style.bg} ${style.text}`}>
      <span className={`w-1.5 h-1.5 rounded-full ${style.dot}`} />
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  );
}

export default function CheckerStockOutReleasePage() {
  const navigate = useNavigate();
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('approved');
  const [actionLoading, setActionLoading] = useState(null);
  const [error, setError] = useState('');

  const token = localStorage.getItem('token');

  const fetchRequests = async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/stock-out-requests', {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || 'Failed to load requests.');
      setRequests(data.data || []);
    } catch (err) {
      setError(err.message || 'Failed to load requests.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchRequests();
  }, []);

  const [notice, setNotice] = useState('');
  const [confirmId, setConfirmId] = useState(null);

  const handleRelease = async (id) => {
    setActionLoading(id);
    setError('');
    setNotice('');
    try {
      const res = await fetch(`/api/stock-out-requests/${id}/release`, {
        method: 'PUT',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || 'Release failed.');
      setNotice(data.message || 'Item released and stock updated.');
      await fetchRequests();
    } catch (err) {
      setError(err.message);
    } finally {
      setActionLoading(null);
      setConfirmId(null);
    }
  };

  const filtered = activeTab === 'all'
    ? requests.filter((r) => r.status === 'approved' || r.status === 'released')
    : requests.filter((r) => r.status === activeTab);

  return (
    <div className="min-h-screen bg-slate-50">
      {/* Sticky top bar */}
      {/* pr-24 leaves room for the floating Night toggle in the top-right corner */}
      <div className="sticky top-0 z-10 bg-white border-b border-black/[0.09] pl-4 pr-24 py-3 flex items-center gap-3">
        <button onClick={() => navigate('/checker/dashboard')} className="text-slate-500">
          <ChevronLeftIcon />
        </button>
        <img src={logo} alt="logo" className="w-7 h-7" />
        <div>
          <h1 className="text-[15px] font-semibold text-slate-900">Stock-Out Release</h1>
          <p className="text-[11px] text-slate-400">Release Admin-approved Maintenance requests. Stock is deducted on release.</p>
        </div>
      </div>

      <div className="px-4 py-4 max-w-lg mx-auto">
        {/* Tabs */}
        <div className="flex gap-2 mb-4">
          {['approved', 'released', 'all'].map((tab) => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab)}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium transition ${
                activeTab === tab
                  ? 'bg-cyan-500 text-white'
                  : 'bg-white border border-black/[0.09] text-slate-500'
              }`}
            >
              {tab.charAt(0).toUpperCase() + tab.slice(1)}
            </button>
          ))}
        </div>

        {error && (
          <div className="mb-3 px-4 py-2.5 rounded-lg bg-red-50 text-red-700 text-sm">
            {error}
          </div>
        )}
        {notice && (
          <div className="mb-3 px-4 py-2.5 rounded-lg bg-emerald-50 text-emerald-700 text-sm">
            {notice}
          </div>
        )}

        {loading ? (
          <p className="text-sm text-slate-500">Loading requests...</p>
        ) : filtered.length === 0 ? (
          <p className="text-sm text-slate-500">No requests in this category.</p>
        ) : (
          <div className="space-y-3">
            {filtered.map((req) => (
              <div
                key={req.id}
                className="bg-white border border-black/[0.09] rounded-2xl shadow-sm px-4 py-3.5"
              >
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="font-semibold text-slate-900">{req.product?.name || req.item_name}</h3>
                    <p className="text-sm text-slate-500">
                      Quantity: {req.quantity} {req.product?.unit || ''}
                    </p>
                    {req.product && req.status === 'approved' && (
                      <p className={`text-xs mt-0.5 ${req.product.current_stock < req.quantity ? 'text-red-600' : 'text-slate-400'}`}>
                        On hand: {req.product.current_stock} {req.product.unit || ''}
                        {req.product.current_stock < req.quantity ? ' (not enough to release)' : ''}
                      </p>
                    )}
                    <p className="text-xs text-slate-400 mt-0.5">Requested by {req.requested_by?.name || 'Unknown'}</p>
                  </div>
                  <StatusBadge status={req.status} />
                </div>

                <p className="text-sm text-slate-600 mt-2">{req.purpose}</p>

                <p className="text-xs text-slate-400 mt-2 flex items-center gap-1">
                  <ClockIcon />
                  Approved{' '}
                  {req.approved_at
                    ? new Date(req.approved_at).toLocaleString()
                    : '—'}
                  {req.approved_by?.name ? ` by ${req.approved_by.name}` : ''}
                </p>

                {req.status === 'released' && req.released_at && (
                  <p className="text-xs text-emerald-600 mt-1">
                    Released {new Date(req.released_at).toLocaleString()}
                    {req.released_by?.name ? ` by ${req.released_by.name}` : ''}
                  </p>
                )}

                {req.status === 'approved' && (
                  confirmId === req.id ? (
                    <div className="mt-3 flex items-center gap-2">
                      <span className="text-sm text-slate-600">Hand over the item and deduct the stock?</span>
                      <button
                        onClick={() => handleRelease(req.id)}
                        disabled={actionLoading === req.id}
                        className="px-3 py-1.5 rounded-lg bg-emerald-500 text-white text-sm font-medium disabled:opacity-50"
                      >
                        {actionLoading === req.id ? 'Releasing…' : 'Yes, release'}
                      </button>
                      <button
                        onClick={() => setConfirmId(null)}
                        className="px-3 py-1.5 rounded-lg border border-black/[0.09] text-sm font-medium text-slate-600"
                      >
                        No
                      </button>
                    </div>
                  ) : (
                    <button
                      onClick={() => setConfirmId(req.id)}
                      className="mt-3 px-3 py-1.5 rounded-lg bg-cyan-500 text-white text-sm font-medium"
                    >
                      Release
                    </button>
                  )
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}