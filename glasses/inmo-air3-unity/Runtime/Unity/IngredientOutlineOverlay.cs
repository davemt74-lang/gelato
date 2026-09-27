using System.Collections;
using Gelato.Ar.Core;
using UnityEngine;
using UnityEngine.UI;

namespace Gelato.Ar.Unity
{
    public sealed class IngredientOutlineOverlay : MonoBehaviour
    {
        [SerializeField] private float lineThickness = 3f;

        private RectTransform _frame;
        private Image[] _edges;
        private Coroutine _hideRoutine;

        private void Awake()
        {
            BuildOverlay();
            Hide();
        }

        public void Show(IngredientObservation observation)
        {
            if (observation == null || observation.BoundingBox == null || observation.BoundingBox.Length < 4)
            {
                Hide();
                return;
            }

            ShowNormalizedTopLeft(
                observation.BoundingBox[0],
                observation.BoundingBox[1],
                observation.BoundingBox[2],
                observation.BoundingBox[3],
                HudLayoutPolicy.TransientOutlineSeconds
            );
        }

        public void ShowNormalizedTopLeft(float x, float y, float width, float height, float seconds)
        {
            x = Mathf.Clamp01(x);
            y = Mathf.Clamp01(y);
            width = Mathf.Clamp(width, 0f, 1f - x);
            height = Mathf.Clamp(height, 0f, 1f - y);

            if (width <= 0f || height <= 0f)
            {
                Hide();
                return;
            }

            _frame.gameObject.SetActive(true);
            _frame.anchorMin = new Vector2(0f, 1f);
            _frame.anchorMax = new Vector2(0f, 1f);
            _frame.pivot = new Vector2(0f, 1f);
            _frame.anchoredPosition = new Vector2(
                x * HudLayoutPolicy.ReferenceWidth,
                -y * HudLayoutPolicy.ReferenceHeight
            );
            _frame.sizeDelta = new Vector2(
                width * HudLayoutPolicy.ReferenceWidth,
                height * HudLayoutPolicy.ReferenceHeight
            );

            if (_hideRoutine != null) StopCoroutine(_hideRoutine);
            _hideRoutine = StartCoroutine(HideAfter(Mathf.Max(0.1f, seconds)));
        }

        public void Hide()
        {
            if (_frame != null) _frame.gameObject.SetActive(false);
            if (_hideRoutine != null)
            {
                StopCoroutine(_hideRoutine);
                _hideRoutine = null;
            }
        }

        private void BuildOverlay()
        {
            var canvasObject = new GameObject("Ingredient Outline Canvas", typeof(RectTransform), typeof(Canvas), typeof(CanvasScaler));
            canvasObject.transform.SetParent(transform, false);

            var canvas = canvasObject.GetComponent<Canvas>();
            canvas.renderMode = RenderMode.ScreenSpaceOverlay();
            canvas.sortingOrder = 20;

            var scaler = canvasObject.GetComponent<CanvasScaler>();
            scaler.uiScaleMode = CanvasScaler.ScaleMode.ScaleWithScreenSize;
            scaler.referenceResolution = new Vector2(HudLayoutPolicy.ReferenceWidth, HudLayoutPolicy.ReferenceHeight);
            scaler.screenMatchMode = CanvasScaler.ScreenMatchMode.MatchWidthOrHeight;
            scaler.matchWidthOrHeight = 0.5f;

            var frameObject = new GameObject("Transient Ingredient Outline", typeof(RectTransform));
            frameObject.transform.SetParent(canvasObject.transform, false);
            _frame = frameObject.GetComponent<RectTransform>();

            _edges = new[]
            {
                CreateEdge(_frame, "Top"),
                CreateEdge(_frame, "Bottom"),
                CreateEdge(_frame, "Left"),
                CreateEdge(_frame, "Right")
            };

            ConfigureHorizontal(_edges[0].rectTransform, true);
            ConfigureHorizontal(_edges[1].rectTransform, false);
            ConfigureVertical(_edges[2].rectTransform, true);
            ConfigureVertical(_edges[3].rectTransform, false);
        }

        private Image CreateEdge(Transform parent, string name)
        {
            var edge = new GameObject(name, typeof(RectTransform), typeof(Image));
            edge.transform.SetParent(parent, false);
            var image = edge.GetComponent<Image>();
            image.color = new Color(0.2f, 1f, 0.55f, 0.95f);
            image.raycastTarget = false;
            return image;
        }

        private void ConfigureHorizontal(RectTransform rect, bool top)
        {
            rect.anchorMin = new Vector2(0f, top ? 1f : 0f);
            rect.anchorMax = new Vector2(1f, top ? 1f : 0f);
            rect.pivot = new Vector2(0.5f, top ? 1f : 0f);
            rect.offsetMin = new Vector2(0f, top ? -lineThickness : 0f);
            rect.offsetMax = new Vector2(0f, top ? 0f : lineThickness);
        }

        private void ConfigureVertical(RectTransform rect, bool left)
        {
            rect.anchorMin = new Vector2(left ? 0f : 1f, 0f);
            rect.anchorMax = new Vector2(left ? 0f : 1f, 1f);
            rect.pivot = new Vector2(left ? 0f : 1f, 0.5f);
            rect.offsetMin = new Vector2(left ? 0f : -lineThickness, 0f);
            rect.offsetMax = new Vector2(left ? lineThickness : 0f, 0f);
        }

        private IEnumerator HideAfter(float seconds)
        {
            yield return new WaitForSeconds(seconds);
            _frame.gameObject.SetActive(false);
            _hideRoutine = null;
        }
    }
}
