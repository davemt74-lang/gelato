#if GELATO_UNITY_ADAPTER_CONTRACT
// Test-only engine surface. Production adapter sources are linked by the project;
// this facade never compiles when the module is imported into Unity Assets.
using System;
using System.Collections.Generic;
using System.IO;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Threading;

namespace UnityEngine
{
    public class MonoBehaviour { }
    [AttributeUsage(AttributeTargets.Field)] public sealed class SerializeField : Attribute { }
    [AttributeUsage(AttributeTargets.Field)] public sealed class HeaderAttribute : Attribute
    {
        public HeaderAttribute(string value) { }
    }
    [AttributeUsage(AttributeTargets.Field)] public sealed class RangeAttribute : Attribute
    {
        public RangeAttribute(float minimum, float maximum) { }
    }
    public static class Time { public static float unscaledTime; }
    public static class Mathf { public static float Max(float a, float b) => Math.Max(a, b); }
    public static class Application { public static string persistentDataPath = Path.GetTempPath(); }
    public static class Debug
    {
        public static readonly List<string> Errors = new List<string>();
        public static void Log(object message) { }
        public static void LogWarning(object message) { }
        public static void LogError(object message) => Errors.Add(message.ToString());
    }
    public static class JsonUtility
    {
        public static string ToJson(object value, bool prettyPrint = false) =>
            JsonSerializer.Serialize(value, value.GetType(), new JsonSerializerOptions
            {
                IncludeFields = true, WriteIndented = prettyPrint
            });

        public static T FromJson<T>(string value)
        {
            using var document = JsonDocument.Parse(value);
            return (T)Read(document.RootElement, typeof(T));
        }

        // Match the public-field DTO shape used by JsonUtility. This does not
        // certify Unity's serializer/IL2CPP; real-engine acceptance remains separate.
        private static object Read(JsonElement element, Type type)
        {
            if (element.ValueKind == JsonValueKind.Null) return null;
            if (type == typeof(string)) return element.GetString();
            if (type == typeof(bool)) return element.GetBoolean();
            if (type == typeof(int)) return element.GetInt32();
            if (type == typeof(long)) return element.GetInt64();
            if (type == typeof(float)) return element.GetSingle();
            if (type == typeof(double)) return element.GetDouble();
            if (type.IsArray)
            {
                var itemType = type.GetElementType();
                var result = Array.CreateInstance(itemType, element.GetArrayLength());
                var index = 0;
                foreach (var item in element.EnumerateArray()) result.SetValue(Read(item, itemType), index++);
                return result;
            }
            var value = Activator.CreateInstance(type, true);
            foreach (var field in type.GetFields(BindingFlags.Instance | BindingFlags.Public))
                if (element.TryGetProperty(field.Name, out var property))
                    field.SetValue(value, Read(property, field.FieldType));
            return value;
        }
    }
}

namespace UnityEngine.Networking
{
    public abstract class UploadHandler : IDisposable
    {
        public byte[] data;
        public bool Disposed;
        public void Dispose() { Disposed = true; }
    }
    public sealed class UploadHandlerRaw : UploadHandler
    {
        public UploadHandlerRaw(byte[] bytes) { data = bytes; }
    }
    public abstract class DownloadHandler : IDisposable
    {
        public byte[] data = Array.Empty<byte>();
        public string text => Encoding.UTF8.GetString(data);
        public bool Disposed;
        public void Dispose() { Disposed = true; }
    }
    public sealed class DownloadHandlerBuffer : DownloadHandler { }
    public sealed class UnityWebRequestAsyncOperation { public bool isDone; }

    public sealed class UnityWebRequest : IDisposable
    {
        public enum Result { InProgress, Success, ConnectionError, ProtocolError, DataProcessingError }
        public const string kHttpVerbPOST = "POST";
        public static int OwnerThread;
        public static Action<UnityWebRequest> OnSend;
        public static readonly List<UnityWebRequest> Requests = new List<UnityWebRequest>();
        public readonly Dictionary<string, string> Headers = new Dictionary<string, string>();
        public readonly string url;
        public readonly string method;
        public UploadHandler uploadHandler;
        public DownloadHandler downloadHandler;
        public Result result = Result.InProgress;
        public long responseCode;
        public string error;
        public int timeout;
        public ulong downloadedBytes;
        public bool Sent;
        public bool Aborted;
        public bool Disposed;
        public int AbortThread;
        public int DisposeThread;
        public bool isNetworkError => result == Result.ConnectionError;
        public bool isHttpError => result == Result.ProtocolError;
        public readonly UnityWebRequestAsyncOperation Operation = new UnityWebRequestAsyncOperation();

        public UnityWebRequest(string address, string verb)
        {
            CheckContext();
            url = address;
            method = verb;
            Requests.Add(this);
        }
        public static UnityWebRequest Get(Uri uri) => new UnityWebRequest(uri.AbsoluteUri, "GET")
        {
            downloadHandler = new DownloadHandlerBuffer()
        };
        public void SetRequestHeader(string key, string value) { CheckContext(); Headers[key] = value; }
        public UnityWebRequestAsyncOperation SendWebRequest()
        {
            CheckContext();
            Sent = true;
            OnSend?.Invoke(this);
            return Operation;
        }
        public string Action
        {
            get
            {
                if (uploadHandler == null) return string.Empty;
                using var json = JsonDocument.Parse(uploadHandler.data);
                return json.RootElement.GetProperty("action").GetString();
            }
        }
        public void Complete(string body, Result status = Result.Success, long code = 200) =>
            Complete(Encoding.UTF8.GetBytes(body), status, code);
        public void Complete(byte[] body, Result status = Result.Success, long code = 200)
        {
            CheckContext();
            downloadHandler.data = body;
            downloadedBytes = (ulong)body.LongLength;
            result = status;
            responseCode = code;
            error = status == Result.Success ? null : "simulated network failure";
            Operation.isDone = true;
        }
        public void Abort()
        {
            CheckContext();
            Aborted = true;
            AbortThread = Thread.CurrentThread.ManagedThreadId;
            result = Result.ConnectionError;
            error = "Request aborted";
            Operation.isDone = true;
        }
        public void Dispose()
        {
            CheckContext();
            Disposed = true;
            DisposeThread = Thread.CurrentThread.ManagedThreadId;
            uploadHandler?.Dispose();
            downloadHandler?.Dispose();
        }
        private static void CheckContext()
        {
            if (Thread.CurrentThread.ManagedThreadId != OwnerThread)
                throw new InvalidOperationException("Unity transport API was invoked outside the engine context.");
        }
    }
}

namespace Gelato.Ar.Unity
{
    public sealed class ArHudRuntimeBinder : UnityEngine.MonoBehaviour
    {
        public void ShowIngredientObservation(Gelato.Ar.Core.IngredientObservation observation) { }
    }
}
#endif
