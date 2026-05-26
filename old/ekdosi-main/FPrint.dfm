object FrmPrint: TFrmPrint
  Left = 0
  Top = 0
  BorderStyle = bsDialog
  Caption = 'FrmPrint'
  ClientHeight = 57
  ClientWidth = 283
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Position = poOwnerFormCenter
  Visible = True
  OnCloseQuery = FormCloseQuery
  PixelsPerInch = 96
  TextHeight = 13
  object Label3: TLabel
    Left = 24
    Top = 23
    Width = 249
    Height = 35
    Cursor = crArrow
    Alignment = taCenter
    AutoSize = False
    Caption = #928#961#959#949#964#959#953#956#945#963#943#945' '#949#954#964#973#960#969#963#951#962'...'
    Font.Charset = GREEK_CHARSET
    Font.Color = clWindowText
    Font.Height = -19
    Font.Name = 'Tahoma'
    Font.Style = []
    ParentFont = False
    Transparent = True
  end
  object Report: TfrxReport
    Version = '6.7.9'
    DotMatrixReport = False
    IniFile = '\Software\Fast Reports'
    PreviewOptions.Buttons = [pbPrint, pbLoad, pbSave, pbExport, pbZoom, pbFind, pbOutline, pbPageSetup, pbTools, pbEdit, pbNavigator, pbExportQuick, pbCopy, pbSelection]
    PreviewOptions.Zoom = 1.000000000000000000
    PrintOptions.Printer = 'Default'
    PrintOptions.PrintOnSheet = 0
    ReportOptions.CreateDate = 39932.492286956000000000
    ReportOptions.LastChange = 41088.462077060200000000
    ScriptLanguage = 'C++Script'
    ShowProgress = False
    StoreInDFM = False
  end
  object PdfExport: TfrxPDFExport
    ShowDialog = False
    ExportNotPrintable = True
    UseFileCache = True
    ShowProgress = False
    OverwritePrompt = False
    DataOnly = False
    EmbedFontsIfProtected = False
    OpenAfterExport = False
    PrintOptimized = True
    Outline = False
    Background = True
    HTMLTags = True
    Quality = 100
    Transparency = False
    Author = 'FastReport'
    Subject = 'PDF Invoice'
    Creator = 'MyIP NET-WORKS OE'
    ProtectionFlags = [eModify, eCopy, eAnnot]
    HideToolbar = False
    HideMenubar = False
    HideWindowUI = False
    FitWindow = True
    CenterWindow = False
    PrintScaling = True
    PdfA = False
    PDFStandard = psNone
    PDFVersion = pv17
    Left = 88
    Top = 65528
  end
  object DotMatrixReport: TfrxDotMatrixExport
    UseFileCache = True
    ShowProgress = True
    OverwritePrompt = True
    DataOnly = False
    EscModel = 0
    GraphicFrames = False
    PageBreaks = False
    SaveToFile = False
    UseIniSettings = True
    Left = 136
    Top = 65528
  end
  object frxCSVExport1: TfrxCSVExport
    UseFileCache = True
    ShowProgress = True
    OverwritePrompt = False
    DataOnly = False
    Separator = ';'
    OEMCodepage = False
    UTF8 = False
    OpenAfterExport = False
    NoSysSymbols = True
    ForcedQuotes = False
    Left = 184
    Top = 65528
  end
  object TimerPrint: TJvTimer
    EventTime = tetPost
    Enabled = False
    Interval = 300
    Threaded = False
    OnTimer = TimerPrintTimer
    Left = 240
    Top = 65528
  end
  object frxIBXComponents1: TfrxIBXComponents
    Left = 40
  end
end
