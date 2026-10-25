/**
 * Curated Lucide icon set (each imported individually so esbuild tree-shakes the rest).
 * To add an icon: import it from 'lucide' and register it in ICONS under a kebab-case name.
 * @module ui/icons
 */
import {
  createElement,
  Activity, ArrowDown, ArrowLeft, ArrowLeftRight, ArrowRight, ArrowUp, ArrowUpDown, Binary, Bookmark, BookmarkPlus,
  Braces, Calendar, Check, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ChevronsLeft,
  ChevronsRight, CircleAlert, CircleCheck, CircleX, Clock, Code, Columns3, Command, Copy, CopyPlus,
  CornerDownLeft, Database, Download, Ellipsis, EllipsisVertical, Eraser, ExternalLink, Eye, EyeOff,
  FileCode, FileDown, FileText, FileUp, Filter, FilterX, Gauge, GripVertical, HardDrive, Hash,
  History, Info, Key, KeyRound, Languages, Layers, Link, ListFilter, LoaderCircle, Lock, LogOut,
  Maximize2, Menu, Minus, Monitor, Moon, Network, PanelLeft, Pause, Pencil, Play, Plug, Plus, RefreshCw, ChartLine,
  Rows3, Save, ScrollText, Search, Server, Settings2, ShieldCheck, SlidersHorizontal, Sparkles, Square,
  SquareFunction, SquareTerminal, Sun, Table, Table2, TextSearch, Timer, Trash2, TriangleAlert,
  UnfoldHorizontal, FoldHorizontal,
  GitCompareArrows, Unplug, Upload, Users, WandSparkles, Workflow, Wrench, X, Zap,
  ZoomIn, ZoomOut,
} from 'lucide';

const ICONS = {
  activity: Activity, 'arrow-down': ArrowDown, 'arrow-left': ArrowLeft, 'arrow-right': ArrowRight,
  'arrow-left-right': ArrowLeftRight, 'git-compare': GitCompareArrows, 'arrow-up': ArrowUp, 'arrow-up-down': ArrowUpDown, binary: Binary, bookmark: Bookmark,
  'bookmark-plus': BookmarkPlus, braces: Braces, calendar: Calendar, check: Check,
  'chevron-down': ChevronDown, 'chevron-left': ChevronLeft, 'chevron-right': ChevronRight,
  'chart-line': ChartLine, 'chevron-up': ChevronUp, 'chevrons-left': ChevronsLeft, 'chevrons-right': ChevronsRight,
  'circle-alert': CircleAlert, 'circle-check': CircleCheck, 'circle-x': CircleX, clock: Clock,
  code: Code, columns: Columns3, command: Command, copy: Copy, 'copy-plus': CopyPlus,
  'corner-down-left': CornerDownLeft, database: Database, download: Download, ellipsis: Ellipsis,
  'ellipsis-vertical': EllipsisVertical, eraser: Eraser, 'external-link': ExternalLink, eye: Eye, 'eye-off': EyeOff,
  'file-code': FileCode, 'file-down': FileDown, 'file-text': FileText, 'file-up': FileUp,
  filter: Filter, 'filter-x': FilterX, logo: Database, gauge: Gauge, grip: GripVertical,
  'hard-drive': HardDrive, hash: Hash, history: History, info: Info, key: Key, 'key-round': KeyRound,
  languages: Languages, layers: Layers, link: Link, 'list-filter': ListFilter, loader: LoaderCircle,
  lock: Lock, 'log-out': LogOut, maximize: Maximize2, 'unfold-horizontal': UnfoldHorizontal, 'fold-horizontal': FoldHorizontal, menu: Menu, minus: Minus, monitor: Monitor,
  moon: Moon, network: Network, 'panel-left': PanelLeft, pause: Pause, pencil: Pencil, play: Play, plug: Plug,
  plus: Plus, refresh: RefreshCw, rows: Rows3, save: Save, scroll: ScrollText, search: Search,
  server: Server, settings: Settings2, 'shield-check': ShieldCheck, sliders: SlidersHorizontal, sparkles: Sparkles, square: Square,
  function: SquareFunction, terminal: SquareTerminal, sun: Sun, table: Table, 'table-2': Table2,
  'text-search': TextSearch, timer: Timer, trash: Trash2, warning: TriangleAlert, unplug: Unplug,
  upload: Upload, users: Users, wand: WandSparkles, workflow: Workflow, wrench: Wrench, x: X, zap: Zap,
  'zoom-in': ZoomIn, 'zoom-out': ZoomOut,
};

/** Icons that point in a reading direction and must be mirrored in RTL layouts. */
const DIRECTIONAL = new Set(['arrow-left', 'arrow-right', 'chevron-left', 'chevron-right', 'chevrons-left', 'chevrons-right', 'corner-down-left', 'log-out', 'external-link']);

/**
 * Create an inline SVG icon (decorative: aria-hidden). Directional icons get
 * `rtl:-scale-x-100` automatically.
 * @param {string} name  key of the curated set (e.g. 'database', 'chevron-right')
 * @param {string} [className]  extra classes; default size is 1rem (size-4)
 * @returns {SVGElement}
 */
export function icon(name, className = '') {
  const node = ICONS[name] || ICONS.square;
  const el = createElement(node);
  const size = /\bsize-/.test(className) ? '' : 'size-4';
  el.setAttribute('class', [size, 'shrink-0', DIRECTIONAL.has(name) ? 'rtl:-scale-x-100' : '', className].filter(Boolean).join(' '));
  el.setAttribute('aria-hidden', 'true');
  el.setAttribute('focusable', 'false');
  el.setAttribute('stroke-width', '1.75');
  return el;
}

/** Names of all registered icons (for docs / debugging). */
export const iconNames = () => Object.keys(ICONS);
