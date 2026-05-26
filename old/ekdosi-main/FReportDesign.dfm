object FrmReportDesign: TFrmReportDesign
  Left = 0
  Top = 0
  Caption = 'FrmReportDesign'
  ClientHeight = 174
  ClientWidth = 236
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Visible = True
  PixelsPerInch = 96
  TextHeight = 13
  object Report: TfrxReport
    Version = '6.7.9'
    DotMatrixReport = True
    IniFile = '\Software\Fast Reports'
    PreviewOptions.Buttons = [pbPrint, pbLoad, pbSave, pbExport, pbZoom, pbFind, pbOutline, pbPageSetup, pbTools, pbEdit, pbNavigator, pbExportQuick]
    PreviewOptions.Zoom = 1.000000000000000000
    PrintOptions.Printer = 'Default'
    PrintOptions.PrintOnSheet = 0
    ReportOptions.CreateDate = 39745.581785659700000000
    ReportOptions.LastChange = 39745.794378067130000000
    ScriptLanguage = 'PascalScript'
    StoreInDFM = False
    Left = 72
    Top = 80
  end
  object frxBarCodeObject1: TfrxBarCodeObject
    Left = 112
    Top = 112
  end
  object frxIBXComponents1: TfrxIBXComponents
    Left = 88
    Top = 48
  end
end
