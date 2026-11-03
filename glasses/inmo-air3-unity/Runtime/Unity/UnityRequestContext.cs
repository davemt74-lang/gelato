using System;
using System.Threading;
using System.Threading.Tasks;

namespace Gelato.Ar.Unity
{
    // Capture during MonoBehaviour.Awake (or a constructor called from Awake).
    // Core coordinators may resume on a worker; Unity transport must not follow them.
    internal sealed class UnityRequestContext
    {
        private readonly SynchronizationContext _context;
        private readonly int _threadId;

        internal UnityRequestContext()
        {
            _context = SynchronizationContext.Current
                ?? throw new InvalidOperationException("Unity transport must be initialized on the Unity synchronization context.");
            _threadId = Thread.CurrentThread.ManagedThreadId;
        }

        internal Task<T> RunAsync<T>(Func<Task<T>> operation)
        {
            if (Thread.CurrentThread.ManagedThreadId == _threadId) return operation();
            var completion = new TaskCompletionSource<T>(TaskCreationOptions.RunContinuationsAsynchronously);
            _context.Post(async _ =>
            {
                try
                {
                    completion.TrySetResult(await operation());
                }
                catch (OperationCanceledException ex)
                {
                    completion.TrySetCanceled(ex.CancellationToken);
                }
                catch (Exception ex)
                {
                    completion.TrySetException(ex);
                }
            }, null);
            return completion.Task;
        }
    }
}
