using System;
using System.Diagnostics;
using System.Threading;
using System.Threading.Tasks;
using UnityEngine.Networking;

namespace Gelato.Ar.Unity
{
    // Called on Unity's context. Cancellation callbacks never call Unity APIs
    // from a timer thread; each yield resumes on the captured Unity context.
    internal static class UnityRequestDeadline
    {
        internal static async Task SendAsync(UnityWebRequest request, TimeSpan deadline,
            CancellationToken cancellationToken, Action inspectProgress = null)
        {
            if (request == null) throw new ArgumentNullException(nameof(request));
            if (deadline <= TimeSpan.Zero || deadline.TotalSeconds > 3600d)
                throw new ArgumentOutOfRangeException(nameof(deadline));
            cancellationToken.ThrowIfCancellationRequested();
            request.timeout = Math.Max(1, (int)Math.Ceiling(deadline.TotalSeconds));
            var elapsed = Stopwatch.StartNew();
            try
            {
                var operation = request.SendWebRequest();
                while (!operation.isDone)
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    if (elapsed.Elapsed >= deadline)
                        throw new TimeoutException("Unity network request exceeded its deadline.");
                    inspectProgress?.Invoke();
                    await Task.Yield();
                }
                cancellationToken.ThrowIfCancellationRequested();
                if (elapsed.Elapsed >= deadline)
                    throw new TimeoutException("Unity network request exceeded its deadline.");
                inspectProgress?.Invoke();
            }
            catch
            {
                request.Abort();
                throw;
            }
        }
    }
}
