using System;
using System.Threading;
using System.Threading.Tasks;
using Gelato.Ar.Core;
using UnityEngine.Networking;

namespace Gelato.Ar.Unity
{
    public sealed class UnityVisionModelArtifactFetcher : IVisionModelArtifactFetcher
    {
        private readonly TimeSpan _downloadDeadline;
        private readonly UnityRequestContext _requestContext;

        public UnityVisionModelArtifactFetcher(TimeSpan? downloadDeadline = null)
        {
            _downloadDeadline = downloadDeadline ?? TimeSpan.FromSeconds(60);
            if (_downloadDeadline <= TimeSpan.Zero || _downloadDeadline.TotalSeconds > 3600d)
                throw new ArgumentOutOfRangeException(nameof(downloadDeadline));
            _requestContext = new UnityRequestContext();
        }

        public Task<byte[]> FetchAsync(Uri artifactUri, long maximumBytes, CancellationToken cancellationToken)
        {
            return _requestContext.RunAsync(() => FetchOnUnityContextAsync(artifactUri, maximumBytes, cancellationToken));
        }

        private async Task<byte[]> FetchOnUnityContextAsync(Uri artifactUri, long maximumBytes, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (artifactUri == null) throw new ArgumentNullException(nameof(artifactUri));
            if (!string.Equals(artifactUri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Vision model artifacts require HTTPS.");
            if (maximumBytes <= 0) throw new ArgumentOutOfRangeException(nameof(maximumBytes));

            using (var request = UnityWebRequest.Get(artifactUri))
            {
                await UnityRequestDeadline.SendAsync(request, _downloadDeadline, cancellationToken, () =>
                {
                    if (request.downloadedBytes > (ulong)maximumBytes)
                        throw new InvalidOperationException("Vision model artifact exceeded the configured download limit.");
                });

#if UNITY_2020_2_OR_NEWER
                if (request.result != UnityWebRequest.Result.Success)
#else
                if (request.isNetworkError || request.isHttpError)
#endif
                    throw new InvalidOperationException("Vision model download failed: " + request.error);

                var data = request.downloadHandler != null ? request.downloadHandler.data : null;
                if (data == null || data.LongLength == 0)
                    throw new InvalidOperationException("Vision model download returned no bytes.");
                if (data.LongLength > maximumBytes)
                    throw new InvalidOperationException("Vision model artifact exceeded the configured download limit.");

                return data;
            }
        }
    }
}
