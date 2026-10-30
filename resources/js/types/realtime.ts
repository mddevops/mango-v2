/**
 * Signals from the personal channel. They carry ids and versions only; the page asks the
 * server for the data it may show. `resync` is raised locally after a reconnect.
 */
export type RealtimeSignal =
    | { type: 'order.updated'; id: string; order_id: number; version: number }
    | {
          type: 'order.assigned';
          id: string;
          order_id: number;
          version: number;
          label: string;
          url: string;
      }
    | { type: 'order.access-check'; id: string; order_id: number }
    | {
          type: 'call.updated';
          id: string;
          order_id: number | null;
          call_id: number;
          version: number;
      }
    | {
          type: 'call.incoming';
          id: string;
          call_id: number;
          version: number;
          order_id: number | null;
          label: string | null;
          url: string | null;
      }
    | { type: 'resync' };
