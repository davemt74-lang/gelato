using Gelato.Ar.Core;
using UnityEngine;
using UnityEngine.UI;

namespace Gelato.Ar.Unity
{
    public sealed class ArHudController : MonoBehaviour
    {
        private Text _itemTitle;
        private Text _itemBody;
        private Text _buildBody;
        private Text _validationBody;
        private Text _nextTitle;
        private Text _nextBody;
        private GameObject _nextPanel;

        private void Awake()
        {
            BuildHud();
        }

        public void Render(HudViewModel model)
        {
            if (model == null) return;

            _itemTitle.text = string.IsNullOrWhiteSpace(model.ItemTitle) ? "NO ACTIVE ITEM" : model.ItemTitle;
            _itemBody.text = model.ItemBody ?? string.Empty;
            _buildBody.text = model.BuildBody ?? string.Empty;
            _validationBody.text = model.ValidationBody ?? string.Empty;
            _nextTitle.text = model.NextTitle ?? string.Empty;
            _nextBody.text = model.NextBody ?? string.Empty;
            _nextPanel.SetActive(model.ShowNext);
        }

        private void BuildHud()
        {
            var canvasObject = new GameObject("Gelato AR HUD Canvas", typeof(RectTransform), typeof(Canvas), typeof(CanvasScaler), typeof(GraphicRaycaster));
            canvasObject.transform.SetParent(transform, false);

            var canvas = canvasObject.GetComponent<Canvas>();
            canvas.renderMode = RenderMode.ScreenSpaceOverlay();
            canvas.sortingOrder = 10;

            var scaler = canvasObject.GetComponent<CanvasScaler>();
            scaler.uiScaleMode = CanvasScaler.ScaleMode.ScaleWithScreenSize;
            scaler.referenceResolution = new Vector2(HudLayoutPolicy.ReferenceWidth, HudLayoutPolicy.ReferenceHeight);
            scaler.screenMatchMode = CanvasScaler.ScreenMatchMode.MatchWidthOrHeight;
            scaler.matchWidthOrHeight = 0.5f;

            var rail = new GameObject("Right Rail", typeof(RectTransform), typeof(VerticalLayoutGroup));
            rail.transform.SetParent(canvasObject.transform, false);
            var railRect = rail.GetComponent<RectTransform>();
            railRect.anchorMin = new Vector2(1f, 1f);
            railRect.anchorMax = new Vector2(1f, 1f);
            railRect.pivot = new Vector2(1f, 1f);
            railRect.anchoredPosition = new Vector2(-HudLayoutPolicy.RightMargin, -48f);
            railRect.sizeDelta = new Vector2(HudLayoutPolicy.RightRailWidth, HudLayoutPolicy.ReferenceHeight - 96f);

            var layout = rail.GetComponent<VerticalLayoutGroup>();
            layout.spacing = 12f;
            layout.padding = new RectOffset(0, 0, 0, 0);
            layout.childAlignment = TextAnchor.UpperCenter;
            layout.childControlWidth = true;
            layout.childForceExpandWidth = true;
            layout.childControlHeight = false;
            layout.childForceExpandHeight = false;

            var item = CreateSection(rail.transform, "ITEM", 142f);
            _itemTitle = item.Title;
            _itemBody = item.Body;

            var build = CreateSection(rail.transform, "BUILD STEPS", 400f);
            _buildBody = build.Body;

            var validation = CreateSection(rail.transform, "PRODUCT VALIDATION", 282f);
            _validationBody = validation.Body;

            var next = CreateSection(rail.transform, "NEXT", 124f);
            _nextPanel = next.Root;
            _nextTitle = next.Title;
            _nextBody = next.Body;
            _nextPanel.SetActive(false);
        }

        private static HudSection CreateSection(Transform parent, string heading, float height)
        {
            var panel = new GameObject(heading, typeof(RectTransform), typeof(Image), typeof(LayoutElement));
            panel.transform.SetParent(parent, false);

            var image = panel.GetComponent<Image>();
            image.color = new Color(0.02f, 0.025f, 0.035f, 0.78f);

            var layout = panel.GetComponent<LayoutElement>();
            layout.preferredHeight = height;
            layout.minHeight = height;

            var title = CreateText(panel.transform, heading + " Title", 28, FontStyle.Bold);
            var titleRect = title.rectTransform;
            titleRect.anchorMin = new Vector2(0f, 1f);
            titleRect.anchorMax = new Vector2(1f, 1f);
            titleRect.pivot = new Vector2(0.5f, 1f);
            titleRect.offsetMin = new Vector2(18f, -58f);
            titleRect.offsetMax = new Vector2(-18f, -14f);
            title.text = heading;
            title.color = new Color(0.55f, 0.9f, 1f, 1f);

            var body = CreateText(panel.transform, heading + " Body", 23, FontStyle.Normal);
            var bodyRect = body.rectTransform;
            bodyRect.anchorMin = new Vector2(0f, 0f);
            bodyRect.anchorMax = new Vector2(1f, 1f);
            bodyRect.offsetMin = new Vector2(18f, 14f);
            bodyRect.offsetMax = new Vector2(-18f, -64f);
            body.color = Color.white;
            body.alignment = TextAnchor.UpperLeft;
            body.horizontalOverflow = HorizontalWrapMode.Wrap;
            body.verticalOverflow = VerticalWrapMode.Truncate;
            body.lineSpacing = 1.08f;

            return new HudSection(panel, title, body);
        }

        private static Text CreateText(Transform parent, string name, int fontSize, FontStyle style)
        {
            var go = new GameObject(name, typeof(RectTransform), typeof(Text));
            go.transform.SetParent(parent, false);
            var text = go.GetComponent<Text>();
            text.font = Resources.GetBuiltinResource<Font>("Arial.ttf");
            text.fontSize = fontSize;
            text.fontStyle = style;
            text.raycastTarget = false;
            return text;
        }

        private sealed class HudSection
        {
            public HudSection(GameObject root, Text title, Text body)
            {
                Root = root;
                Title = title;
                Body = body;
            }

            public GameObject Root { get; }
            public Text Title { get; }
            public Text Body { get; }
        }
    }
}
