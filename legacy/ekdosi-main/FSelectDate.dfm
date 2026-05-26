object FrmSelectDate: TFrmSelectDate
  Left = 0
  Top = 0
  BorderIcons = [biSystemMenu]
  Caption = #917#960#953#955#941#958#964#949' '#951#956#949#961#959#956#951#957#943#945
  ClientHeight = 204
  ClientWidth = 220
  Color = clBtnFace
  Font.Charset = DEFAULT_CHARSET
  Font.Color = clWindowText
  Font.Height = -11
  Font.Name = 'Tahoma'
  Font.Style = []
  FormStyle = fsMDIChild
  OldCreateOrder = False
  Visible = True
  OnCloseQuery = FormCloseQuery
  DesignSize = (
    220
    204)
  PixelsPerInch = 96
  TextHeight = 13
  object Calendar: TMonthCalendar
    Left = 0
    Top = 0
    Width = 220
    Height = 164
    Hint = #917#960#953#955#941#958#964#949' '#951#956#949#961#959#956#951#957#943#945
    Align = alTop
    Anchors = [akLeft, akTop, akRight, akBottom]
    Date = 39944.446781990740000000
    ParentShowHint = False
    ShowHint = True
    ShowToday = False
    TabOrder = 0
  end
  object buttonOk: TJvDotNetButton
    Left = 137
    Top = 171
    Width = 75
    Height = 25
    Anchors = [akRight, akBottom]
    Caption = #917#960#953#955#959#947#942
    TabOrder = 1
    OnClick = buttonOkClick
  end
end
