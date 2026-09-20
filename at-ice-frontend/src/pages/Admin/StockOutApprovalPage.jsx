import { useState, useEffect } from 'react';
import AdminLayout from '../../components/AdminLayout';

const STATUS_STYLES = {
  pending: { bg: 'bg-amber-50', text: 'text-amber-700', dot: 'bg-amber-500' },
  approved: { bg: 'bg-cyan-50', text: 'text-cyan-700', dot: 'bg-cyan-500' },
  rejected: { bg: 'bg-red-50', text: 'text-red-700', dot: 'bg-red-500' },
  released: { bg: 'bg-emerald-50', text: 'text-emerald-700', dot: 'bg-emerald-500' },
};

const TABS = ['all', 'pending', 'approved', 'rejected', 'released'];

function StatusBadge({ status }) {
  const style = STATUS_STYLES[status] || STATUS_STYLES.pending;
  return (
    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${style.bg} ${style.text}`}>
      <span className={`w-1.5 h-1.5 rounded-full ${style.dot}`} />
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  );
}

export default function StockOutApprovalPage() {
  const [requests, setRequests] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('pending');
  const [rejectingId, setRejectingId] = useState(null);
  const [rejectionReason, setRejectionReason] = useState('');
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
      const data = await res.json();
      setRequests(data.data || []);
    } catch (err) {
      setError('Failed to load requests.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchRequests();
  }, []);

  const handleApprove = async (id) => {
    setActionLoading(id);
    setError('');
    try {
      const res = await fetch(`/api/stock-out-requests/${id}/approve`, {
        method: 'PUT',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });
      if (!res.ok) {
        const data = await res.json();
        throw new Error(data.message || 'Approve failed.');
      }
      await fetchRequests();
    } catch (err) {
      setError(err.message);
    } finally {
      setActionLoading(null);
    }
  };

  const handleRejectSubmit = async (id) => {
    if (!rejectionReason.trim()) {
      setError('Rejection reason is required.');
      return;
    }
    setActionLoading(id);
    setError('');
    try {
      const res = await fetch(`/api/stock-out-requests/${id}/reject`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ rejection_reason: rejectionReason }),
      });
      if (!res.ok) {
        const data = await res.json();
        throw new Error(data.message || 'Reject failed.');
      }
      setRejectingId(null);
      setRejectionReason('');
      await fetchRequests();
    } catch (err) {
      setError(err.message);
    } finally {
      setActionLoading(null);
    }
  };

  const handleRelease = async (id) => {
    setActionLoading(id);
    setError('');
    try {
      const res = await fetch(`/api/stock-out-requests/${id}/release`, {
        method: 'PUT',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });
      if (!res.ok) {
        const data = await res.json();
        throw new Error(data.message || 'Release failed.');
      }
      await fetchRequests();
    } catch (err) {
      setError(err.message);
    } finally {
      setActionLoading(null);
    }
  };

  const filtered = activeTab === 'all'
    ? requests
    : requests.filter((r) => r.status === activeTab);

  return (
    <AdminLayout pageTitle="Maintenance Stock-Out Approvals" breadcrumb="Admin / Stock-Out Approvals">
      <div className="max-w-3xl mx-auto">
        <div className="flex gap-2 mb-5 border-b border-black/[0.09] pb-3">
          {TABS.map((tab) => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab)}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium transition ${
                activeTab === tab
                  ? 'bg-cyan-500 text-white'
                  : 'text-slate-500 hover:bg-black/[0.04]'
              }`}
            >
              {tab.charAt(0).toUpperCase() + tab.slice(1)}
            </button>
          ))}
        </div>

        {error && (
          <div className="mb-4 px-4 py-2.5 rounded-lg bg-red-50 text-red-700 text-sm">
            {error}
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
                className="bg-white border border-black/[0.09] rounded-2xl shadow-sm px-5 py-4"
              >
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="font-semibold text-slate-900">{req.item_name}</h3>
                    <p className="text-sm text-slate-500">Quantity: {req.quantity}</p>
                  </div>
                  <StatusBadge status={req.status} />
                </div>

                <p className="text-sm text-slate-600 mt-2">{req.purpose}</p>

                <p className="text-xs text-slate-400 mt-2">
                  Requested by {req.requested_by?.name || 'Unknown'} ·{' '}
                  {new Date(req.created_at).toLocaleString()}
                </p>

                {req.status === 'approved' && req.approved_by && (
                  <p className="text-xs text-slate-400 mt-1">
                    Approved by {req.approved_by.name}
                    {req.approved_at ? ` · ${new Date(req.approved_at).toLocaleString()}` : ''}
                  </p>
                )}

                {req.status === 'released' && (
                  <p className="text-xs text-slate-400 mt-1">
                    Approved by {req.approved_by?.name || 'Unknown'}
                    {req.approved_at ? ` · ${new Date(req.approved_at).toLocaleString()}` : ''}
                    {' — '}
                    Released by {req.released_by?.name || 'Unknown'}
                    {req.released_at ? ` · ${new Date(req.released_at).toLocaleString()}` : ''}
                  </p>
                )}

                {req.status === 'rejected' && req.rejection_reason && (
                  <div className="mt-2 px-3 py-2 rounded-lg bg-red-50 text-red-700 text-sm">
                    Reason: {req.rejection_reason}
                  </div>
                )}

                {req.status === 'pending' && (
                  <div className="mt-3">
                    {rejectingId === req.id ? (
                      <div className="flex flex-col gap-2">
                        <textarea
                          value={rejectionReason}
                          onChange={(e) => setRejectionReason(e.target.value)}
                          placeholder="Reason for rejection..."
                          className="w-full text-sm border border-black/[0.09] rounded-lg px-3 py-2 resize-none"
                          rows={2}
                        />
                        <div className="flex gap-2">
                          <button
                            onClick={() => handleRejectSubmit(req.id)}
                            disabled={actionLoading === req.id}
                            className="px-3 py-1.5 rounded-lg bg-red-500 text-white text-sm font-medium disabled:opacity-50"
                          >
                            Confirm Reject
                          </button>
                          <button
                            onClick={() => {
                              setRejectingId(null);
                              setRejectionReason('');
                              setError('');
                            }}
                            className="px-3 py-1.5 rounded-lg border border-black/[0.09] text-sm font-medium text-slate-600"
                          >
                            Cancel
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div className="flex gap-2">
                        <button
                          onClick={() => handleApprove(req.id)}
                          disabled={actionLoading === req.id}
                          className="px-3 py-1.5 rounded-lg bg-cyan-500 text-white text-sm font-medium disabled:opacity-50"
                        >
                          Approve
                        </button>
                        <button
                          onClick={() => setRejectingId(req.id)}
                          className="px-3 py-1.5 rounded-lg border border-black/[0.09] text-sm font-medium text-slate-600"
                        >
                          Reject
                        </button>
                      </div>
                    )}
                  </div>
                )}

                {/* NEW: Release action for approved requests */}
                {req.status === 'approved' && (
                  <div className="mt-3">
                    <button
                      onClick={() => handleRelease(req.id)}
                      disabled={actionLoading === req.id}
                      className="px-3 py-1.5 rounded-lg bg-emerald-500 text-white text-sm font-medium disabled:opacity-50"
                    >
                      {actionLoading === req.id ? 'Releasing…' : 'Release'}
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </AdminLayout>
  );
}